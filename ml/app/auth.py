import hmac
from typing import Annotated

from fastapi import Header, HTTPException, status

from app.settings import get_settings


def require_token(authorization: Annotated[str, Header()] = "") -> None:
    scheme, _, token = authorization.partition(" ")
    expected = get_settings().ml_service_token

    if scheme.lower() != "bearer" or not hmac.compare_digest(token.encode(), expected.encode()):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing token",
            headers={"WWW-Authenticate": "Bearer"},
        )
