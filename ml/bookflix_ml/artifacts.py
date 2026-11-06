"""Model artifacts: S3 when ARTIFACT_BUCKET is set, else a local folder. boto3 is only imported for S3."""

import tempfile
from pathlib import Path
from typing import Any

import joblib

from app.settings import get_settings

PREFIX = "ml-artifacts"


def save(obj: Any, kind: str, version: str, filename: str = "model.joblib") -> str:
    """Write `obj` under `<kind>/<version>/` and return its URI."""
    settings = get_settings()
    key = f"{PREFIX}/{kind}/{version}/{filename}"

    if settings.artifact_bucket:
        import boto3

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / filename
            joblib.dump(obj, path)
            boto3.client("s3").upload_file(str(path), settings.artifact_bucket, key)

        return f"s3://{settings.artifact_bucket}/{key}"

    path = Path(settings.artifact_dir) / key
    path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(obj, path)

    return str(path)


def load(uri: str) -> Any:
    """Load an artifact this service wrote. Joblib files are pickles, so a URI outside our own bucket or folder is refused."""
    settings = get_settings()

    if uri.startswith("s3://"):
        if not settings.artifact_bucket or not uri.startswith(
            f"s3://{settings.artifact_bucket}/{PREFIX}/"
        ):
            raise ValueError("Refusing to load an artifact from outside the artifact bucket.")

        import boto3

        key = uri.removeprefix(f"s3://{settings.artifact_bucket}/")
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "model.joblib"
            boto3.client("s3").download_file(settings.artifact_bucket, key, str(path))
            return joblib.load(path)

    path = Path(uri).resolve()
    if not path.is_relative_to((Path(settings.artifact_dir) / PREFIX).resolve()):
        raise ValueError("Refusing to load an artifact from outside the artifact directory.")

    return joblib.load(path)
