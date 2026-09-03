"""Trains the neural forecasting models.

    python -m training.train --data ../storage/app/ml/training_data.csv

Trains DeepAR (the probabilistic benchmark) and the Temporal Fusion
Transformer (the covariate-aware main model), writes a checkpoint plus the
dataset parameters needed to reconstruct the feature pipeline at serve time,
and prints validation scores for both.

Everything runs on CPU by design - a few hundred series does not justify a GPU,
and keeping it CPU-only means the same command works on any machine that can
run the service.
"""

from __future__ import annotations

import argparse
import json
import warnings
from pathlib import Path

import lightning.pytorch as pl
import numpy as np
import torch
from lightning.pytorch.callbacks import EarlyStopping, ModelCheckpoint
from pytorch_forecasting import DeepAR, TemporalFusionTransformer
from pytorch_forecasting.metrics import NegativeBinomialDistributionLoss, QuantileLoss

from training import metrics
from training.prediction import point_forecast
from training.dataset import GROUP_ID, TARGET, build_datasets, load_frame

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"

# Held constant so that a re-run reproduces the same split and initialisation;
# without it, comparing two training runs tells you nothing.
SEED = 42


def save_serving_artifacts(name: str, bundle) -> Path:
    """Persist everything `app/forecasting/neural.py` needs to rebuild this
    model's feature pipeline at serve time.

    A checkpoint alone is not servable. `TimeSeriesDataSet` holds *fitted*
    state - the categorical encoders that map a sku_id to an embedding row,
    the per-series target normaliser, the continuous scalers - and a model
    fed inputs encoded any other way returns confident nonsense with no
    error. `get_parameters()` is that fitted state; `from_parameters()`
    rebuilds an identical pipeline around new data.

    `epoch_date` is saved with it because `time_idx` is a *known-future real*
    the model reads directly: it is days since the first date in the training
    frame, so serving has to resolve a calendar date to the same integer the
    model was trained against. Recomputing it from a serving window would
    place every request at time_idx ~0 and misrepresent the date to the model
    entirely.
    """
    directory = MODELS_DIR / name
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / "dataset_params.pt"

    torch.save(
        {
            "dataset_parameters": bundle.training.get_parameters(),
            "epoch_date": bundle.frame["date"].min().to_pydatetime().date().isoformat(),
            "training_cutoff": int(bundle.training_cutoff),
            "last_training_date": bundle.frame["date"].max().to_pydatetime().date().isoformat(),
        },
        path,
    )

    print(f"  serving artifacts -> {path}")

    return path


def emit_serving_artifacts(frame, args) -> None:
    """Rebuild and save `dataset_params.pt` for checkpoints that already exist.

    The feature pipeline is a deterministic function of the training frame and
    the split/loss config, so it can be reconstructed exactly without touching
    the weights — which is what makes this a minutes-long recovery rather than
    a retraining run.

    **The config must match what the checkpoint was trained with.** A pipeline
    built with the wrong `holdout_days` fits its normaliser over a different
    span, and the wrong `count_target` picks a different transformation
    entirely; either rescales every input the model sees and corrupts every
    prediction, silently. So the config is read back from
    `training_summary.json` rather than from this run's CLI flags, the same
    reason `evaluate.py` reads it.
    """
    summary_path = MODELS_DIR / "training_summary.json"

    if not summary_path.exists():
        raise SystemExit(
            f"{summary_path} not found — cannot know what config the checkpoints "
            "were trained with, and guessing would silently corrupt every prediction."
        )

    config = json.loads(summary_path.read_text(encoding="utf-8")).get("config", {})
    holdout_days = config.get("holdout_days", args.holdout_days)
    count_target = config.get("tft_loss", args.tft_loss) == "negative_binomial"

    print(f"  config from training_summary.json: holdout_days={holdout_days}, count_target={count_target}")

    for name in ("deepar", "tft"):
        if not (MODELS_DIR / name / "best.ckpt").exists():
            print(f"  no checkpoint for {name}, skipping")
            continue

        bundle = build_datasets(
            frame,
            for_deepar=(name == "deepar"),
            holdout_days=holdout_days,
            # DeepAR always uses a count likelihood; the TFT's follows the loss
            # it was actually trained with.
            count_target=True if name == "deepar" else count_target,
        )

        save_serving_artifacts(name, bundle)


def _dataloaders(bundle, batch_size: int):
    # num_workers=0 deliberately: this project's development environment is
    # Windows, where DataLoader worker processes re-import the module and
    # deadlock on the lightning trainer more often than they speed anything up
    # at this dataset size.
    train_loader = bundle.training.to_dataloader(
        train=True, batch_size=batch_size, num_workers=0
    )
    val_loader = bundle.validation.to_dataloader(
        train=False, batch_size=batch_size * 4, num_workers=0
    )
    return train_loader, val_loader


class EpochReport(pl.Callback):
    """One readable line per epoch, in place of the progress bar."""

    def __init__(self, name: str) -> None:
        self.name = name
        self._train_loss: float | None = None

    def on_train_epoch_end(self, trainer, module) -> None:
        # Captured here rather than read in on_validation_epoch_end: lightning
        # runs validation before the training epoch's aggregate is logged, so
        # reading train_loss_epoch there reports nan for the first epoch.
        value = trainer.callback_metrics.get("train_loss_epoch")
        self._train_loss = float(value) if value is not None else None

    def on_validation_epoch_end(self, trainer, module) -> None:
        if trainer.sanity_checking:
            return

        val_loss = trainer.callback_metrics.get("val_loss")

        train = "     n/a" if self._train_loss is None else f"{self._train_loss:.5f}"

        print(
            f"  [{self.name}] epoch {trainer.current_epoch:>2}  "
            f"train_loss={train}  "
            f"val_loss={float(val_loss) if val_loss is not None else float('nan'):.5f}",
            flush=True,
        )


def _clear_checkpoints(name: str) -> None:
    """Remove previous checkpoints for this model before retraining.

    ModelCheckpoint does not overwrite: given an existing `best.ckpt` it
    writes `best-v1.ckpt` and leaves the old file in place. Anything that
    then loads `best.ckpt` by name silently gets the *older* model - which is
    how a one-epoch smoke-test checkpoint can end up being the thing you
    evaluate and report. Clearing first keeps `best.ckpt` unambiguous.
    """
    directory = MODELS_DIR / name

    if not directory.exists():
        return

    for checkpoint in directory.glob("*.ckpt"):
        checkpoint.unlink()


def _trainer(name: str, max_epochs: int, patience: int, limit_train_batches: int) -> pl.Trainer:
    _clear_checkpoints(name)

    return pl.Trainer(
        max_epochs=max_epochs,
        accelerator="cpu",
        enable_model_summary=False,
        # The tqdm progress bar writes thousands of carriage-return lines to a
        # redirected log, which makes a backgrounded run unreadable. EpochReport
        # below prints one line per epoch instead.
        enable_progress_bar=False,
        gradient_clip_val=0.1,
        log_every_n_steps=25,
        # ~400k training windows is far more than these models need per epoch
        # on CPU. Sampling a subset each epoch and running more epochs reaches
        # the same place much faster, and lets early stopping actually get a
        # say instead of the run being bounded by wall-clock patience.
        limit_train_batches=limit_train_batches,
        default_root_dir=str(MODELS_DIR / "logs" / name),
        callbacks=[
            EpochReport(name),
            EarlyStopping(
                monitor="val_loss",
                patience=patience,
                mode="min",
                min_delta=1e-4,
            ),
            ModelCheckpoint(
                dirpath=str(MODELS_DIR / name),
                filename="best",
                monitor="val_loss",
                mode="min",
                save_top_k=1,
            ),
        ],
    )


def _score_validation(model, bundle, val_loader) -> metrics.Scores:
    """Score point predictions on the validation horizon.

    Goes through `point_forecast` so a quantile-trained model is scored on an
    expected value rather than its median. Scoring the raw median here is what
    made an earlier run report "TFT validation: WAPE 1.0000 / Bias -1.0000" -
    the arithmetic was right, the statistic was wrong, and the number looked
    like total model failure rather than a reporting choice.

    Actuals come from the dataset's own decoder targets rather than
    `return_y=True`, which raises on a ragged final batch.
    """
    predicted = point_forecast(model, val_loader)

    actual = np.concatenate(
        [y[0].cpu().numpy() for _, y in iter(val_loader)], axis=0
    )

    return metrics.score(actual, predicted)


def train_deepar(bundle, args) -> dict:
    print("\n" + "=" * 72)
    print("DeepAR - probabilistic benchmark (negative-binomial likelihood)")
    print("=" * 72)

    train_loader, val_loader = _dataloaders(bundle, args.batch_size)

    model = DeepAR.from_dataset(
        bundle.training,
        learning_rate=args.learning_rate,
        hidden_size=args.deepar_hidden_size,
        rnn_layers=2,
        dropout=0.1,
        # Counts, not a Gaussian. Negative binomial handles the
        # over-dispersion and the hard zero floor that retail demand has and a
        # normal distribution does not.
        loss=NegativeBinomialDistributionLoss(),
        optimizer="adam",
    )

    trainer = _trainer("deepar", args.max_epochs, args.patience, args.limit_train_batches)
    trainer.fit(model, train_dataloaders=train_loader, val_dataloaders=val_loader)

    best_path = trainer.checkpoint_callback.best_model_path
    best = DeepAR.load_from_checkpoint(best_path, map_location="cpu")
    scores = _score_validation(best, bundle, val_loader)

    print(f"\nDeepAR validation: {scores}")
    save_serving_artifacts("deepar", bundle)

    return {"name": "deepar", "checkpoint": best_path, "scores": scores.as_dict()}


def train_tft(bundle, args) -> dict:
    print("\n" + "=" * 72)
    print(f"Temporal Fusion Transformer - main forecast ({args.tft_loss} loss)")
    print("=" * 72)

    train_loader, val_loader = _dataloaders(bundle, args.batch_size)

    if args.tft_loss == "negative_binomial":
        # A count likelihood, matching DeepAR's. Its point prediction is a mean
        # by construction, which matters here: quantile loss predicts the
        # median, and the median of ~71%-zero demand is zero - so a 30-day
        # total built from medians collapses to zero for most SKUs. Using the
        # same loss as DeepAR also isolates the architecture comparison from
        # the loss comparison, which the quantile variant confounds.
        loss = NegativeBinomialDistributionLoss()
    else:
        # Quantile loss gives the prediction interval directly, which is what
        # the app's lower_qty/upper_qty columns want and what a service-level
        # safety-stock calculation should be reading instead of assuming
        # normally distributed demand. Its point output needs converting to an
        # expected value - see evaluate.py::_expected_value_from_quantiles.
        loss = QuantileLoss(quantiles=[0.02, 0.1, 0.25, 0.5, 0.75, 0.9, 0.98])

    print(f"  loss: {type(loss).__name__}")

    model = TemporalFusionTransformer.from_dataset(
        bundle.training,
        learning_rate=args.learning_rate,
        hidden_size=args.tft_hidden_size,
        attention_head_size=args.tft_attention_heads,
        dropout=0.1,
        hidden_continuous_size=max(8, args.tft_hidden_size // 2),
        loss=loss,
        optimizer="adam",
        reduce_on_plateau_patience=2,
    )

    trainer = _trainer("tft", args.max_epochs, args.patience, args.limit_train_batches)
    trainer.fit(model, train_dataloaders=train_loader, val_dataloaders=val_loader)

    best_path = trainer.checkpoint_callback.best_model_path
    best = TemporalFusionTransformer.load_from_checkpoint(best_path, map_location="cpu")
    scores = _score_validation(best, bundle, val_loader)

    print(f"\nTFT validation: {scores}")
    save_serving_artifacts("tft", bundle)

    return {"name": "tft", "checkpoint": best_path, "scores": scores.as_dict()}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--data",
        default=str(Path(__file__).resolve().parents[2] / "storage" / "app" / "ml" / "training_data.csv"),
        help="Path to the CSV written by app:export-ml-training-data",
    )
    parser.add_argument("--max-epochs", type=int, default=12)
    parser.add_argument("--patience", type=int, default=3)
    parser.add_argument("--batch-size", type=int, default=128)
    parser.add_argument("--limit-train-batches", type=int, default=250)
    parser.add_argument(
        "--holdout-days",
        type=int,
        help=(
            "Days reserved from the end for validation + evaluation. Defaults to a "
            "minimal two-horizon holdout; pass ~365 to leave a full seasonal cycle "
            "so evaluate.py can score windows that actually contain promotions and "
            "festivals."
        ),
    )
    parser.add_argument("--learning-rate", type=float, default=0.02)
    parser.add_argument("--deepar-hidden-size", type=int, default=32)
    parser.add_argument("--tft-hidden-size", type=int, default=16)
    parser.add_argument("--tft-attention-heads", type=int, default=2)
    parser.add_argument(
        "--tft-loss",
        choices=["quantile", "negative_binomial"],
        default="quantile",
        help=(
            "Loss for the TFT. quantile gives prediction intervals but a median "
            "point forecast, which collapses to zero on intermittent demand; "
            "negative_binomial gives a mean by construction and matches DeepAR."
        ),
    )
    parser.add_argument(
        "--only",
        choices=["deepar", "tft"],
        help="Train just one model instead of both",
    )
    parser.add_argument(
        "--emit-serving-artifacts",
        action="store_true",
        help=(
            "Skip training and only (re)write dataset_params.pt beside the existing "
            "checkpoints, rebuilding the feature pipeline from the config recorded in "
            "training_summary.json. Recovery path for a checkpoint trained before those "
            "artifacts were written — without it the checkpoint cannot be served, and "
            "retraining just to produce a 200KB file costs hours."
        ),
    )
    args = parser.parse_args()

    # pytorch-forecasting emits a large volume of deprecation noise from its
    # lightning integration that obscures the actual training output.
    warnings.filterwarnings("ignore", category=UserWarning)
    warnings.filterwarnings("ignore", category=FutureWarning)

    pl.seed_everything(SEED, workers=True)
    torch.set_num_threads(max(1, (torch.get_num_threads() or 2)))

    MODELS_DIR.mkdir(parents=True, exist_ok=True)

    print(f"Loading {args.data} ...")
    frame = load_frame(args.data)
    print(
        f"  {len(frame):,} rows | {frame[GROUP_ID].nunique():,} series | "
        f"{frame['date'].min().date()} -> {frame['date'].max().date()} | "
        f"{(frame[TARGET] == 0).mean():.1%} zero-demand days"
    )

    if args.emit_serving_artifacts:
        emit_serving_artifacts(frame, args)

        return

    results = []

    if args.only in (None, "deepar"):
        # DeepAR's likelihood requires an uncentred target; the TFT's does not.
        results.append(
            train_deepar(
                build_datasets(frame, for_deepar=True, holdout_days=args.holdout_days), args
            )
        )

    if args.only in (None, "tft"):
        results.append(
            train_tft(
                build_datasets(
                    frame,
                    for_deepar=False,
                    holdout_days=args.holdout_days,
                    count_target=(args.tft_loss == "negative_binomial"),
                ),
                args,
            )
        )

    # The split and loss are recorded, not just the scores. evaluate.py must
    # rebuild the prediction dataset with the *same* holdout and the same
    # target normaliser the checkpoint was trained with - a mismatch rescales
    # the encoder inputs and silently corrupts every prediction, with no error
    # and a plausible-looking number at the end. Reading it from here removes
    # the need to remember matching CLI flags across two commands.
    summary_path = MODELS_DIR / "training_summary.json"
    summary_path.write_text(
        json.dumps(
            {
                "config": {
                    "holdout_days": args.holdout_days,
                    "tft_loss": args.tft_loss,
                    "max_epochs": args.max_epochs,
                    "patience": args.patience,
                    "limit_train_batches": args.limit_train_batches,
                    "batch_size": args.batch_size,
                    "learning_rate": args.learning_rate,
                    "seed": SEED,
                },
                "models": results,
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    print("\n" + "=" * 72)
    print("Validation summary")
    print("=" * 72)
    for result in results:
        s = result["scores"]
        print(
            f"  {result['name']:<8} WAPE {s['wape']:7.4f}   "
            f"MAE {s['mae']:7.4f}   Bias {s['bias']:+7.4f}"
        )
    print(f"\nWritten to {summary_path}")


if __name__ == "__main__":
    main()
