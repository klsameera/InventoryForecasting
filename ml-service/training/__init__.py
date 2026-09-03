"""Training pipeline for the neural forecasting models.

**One module here is shared with serving: `training.prediction`.** Everything
else (`train`, `evaluate`, `dataset`, `metrics`) is training-only and is never
imported by `app/`.

That single exception is deliberate. `prediction.point_forecast()` decides
whether a checkpoint's raw output is a mean or a median and converts
accordingly, reading the answer off the model's own loss object. Duplicating
that in the serving path is exactly the bug this module was extracted to fix —
`train.py` and `evaluate.py` previously scored the same checkpoint differently
and disagreed by a factor that looked like total model failure. A third
implementation in `neural.py` would reintroduce it, with the serving numbers
being the ones nobody backtests.

It is safe to import from serving: `prediction` needs only `numpy` and
`pytorch_forecasting`, both of which are already serving dependencies because
the checkpoints run in-process. It does not pull in `pandas`-heavy training
code — though `neural.py` needs pandas for its own frame assembly regardless.
"""
