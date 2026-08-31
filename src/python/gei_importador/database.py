from __future__ import annotations

from contextlib import contextmanager
from typing import Any
from typing import Iterator

from gei_importador.config import Config


@contextmanager
def conectar(config: Config) -> Iterator[Any]:
    import psycopg

    conn = psycopg.connect(
        host=config.pghost,
        port=config.pgport,
        dbname=config.pgdatabase,
        user=config.pguser,
        password=config.pgpassword,
        autocommit=True,
    )
    try:
        yield conn
    finally:
        conn.close()
