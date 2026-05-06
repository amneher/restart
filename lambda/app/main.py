import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from mangum import Mangum

from app.database import close_db, init_db
from app.routes import health_router, items_router, registry_router

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    init_db()
    yield
    close_db()


app = FastAPI(
    title="Restart Registry API",
    description="A FastAPI application designed for AWS Lambda with SQLite",
    version="0.1.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"],
    allow_headers=["Authorization", "Content-Type"],
)

@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    body = await request.body()
    logger.warning("422 Validation error on %s %s | body: %s | errors: %s",
                   request.method, request.url.path, body.decode()[:500], exc.errors())
    return JSONResponse(status_code=422, content={"detail": exc.errors()})


app.include_router(health_router)
app.include_router(items_router)
app.include_router(registry_router)

handler = Mangum(app, lifespan="off")
