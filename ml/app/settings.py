from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    ml_service_token: str
    embedding_model: str = "intfloat/multilingual-e5-small"
    embedding_dims: int = 384


@lru_cache
def get_settings() -> Settings:
    return Settings()  # type: ignore[call-arg]  # ml_service_token comes from the environment
