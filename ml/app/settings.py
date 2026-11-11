from functools import lru_cache
from typing import Annotated

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

from bookflix_ml.embeddings import DIMS, MODEL_NAME, MODEL_REVISION


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    ml_service_token: Annotated[str, Field(min_length=32)]
    ml_enable_docs: bool = False
    embedding_model: str = MODEL_NAME
    embedding_revision: str = MODEL_REVISION
    embedding_dims: int = DIMS
    # Training jobs: the direct (non-pooled) Postgres URL, and where artifacts go (S3 when a bucket is set, else a local folder).
    database_url: str = ""
    artifact_bucket: str = ""
    artifact_dir: str = "artifacts"
    lsa_components: int = 200


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]  # ml_service_token comes from the environment
