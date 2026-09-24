FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY defuse_decrypt.py export_via_db.py export_via_api.py ./

ENTRYPOINT ["python3", "export_via_db.py"]
