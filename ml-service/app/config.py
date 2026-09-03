"""Environment-based settings. No secrets are hardcoded; everything comes
from the process environment (see .env.example) so the same image can move
between local dev and any future deployment target without a code change.
"""

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    # Comma-separated list of tokens this service accepts on the
    # `Authorization: Bearer <token>` header. Empty by default (no auth) for
    # local development — see the README's "Authentication" section before
    # this service is ever exposed off localhost (app_plan.md §74).
    api_tokens: frozenset[str]
    host: str
    port: int


def load_settings() -> Settings:
    raw_tokens = os.environ.get("ML_SERVICE_API_TOKENS", "")
    tokens = frozenset(t.strip() for t in raw_tokens.split(",") if t.strip())

    return Settings(
        api_tokens=tokens,
        host=os.environ.get("ML_SERVICE_HOST", "127.0.0.1"),
        port=int(os.environ.get("ML_SERVICE_PORT", "8090")),
    )


settings = load_settings()
