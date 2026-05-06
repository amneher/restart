from fastapi import APIRouter

router = APIRouter(tags=["Health"])


@router.get("/health")
def health_check():
    return {"status": "healthy", "service": "aws-lambda-fastapi"}


@router.get("/")
def root():
    return {"message": "Welcome to AWS Lambda FastAPI"}
