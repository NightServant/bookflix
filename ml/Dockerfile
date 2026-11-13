FROM python:3.12-slim

COPY --from=ghcr.io/astral-sh/uv:0.12.17 /uv /usr/local/bin/uv

WORKDIR /app

# The `embed` extra brings sentence-transformers and CPU-only torch; only this image installs it.
COPY pyproject.toml uv.lock ./
RUN uv sync --frozen --no-dev --extra embed

COPY app ./app
COPY bookflix_ml ./bookflix_ml

ENV PATH="/app/.venv/bin:$PATH" \
    HF_HOME=/app/.cache/huggingface

# The embedding model is downloaded here, at build time; the service only ever reads it from HF_HOME.
RUN python -m bookflix_ml.embeddings

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
