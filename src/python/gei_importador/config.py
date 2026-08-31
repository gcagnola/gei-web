from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Config:
    """Configuracion del importador obtenida del entorno."""

    laravel_liquidaciones_dir: Path
    pghost: str
    pgport: int
    pgdatabase: str
    pguser: str
    pgpassword: str
    importador_base_dir: Path


def _default_laravel_liquidaciones_dir() -> Path:
    proyectos = Path(__file__).resolve().parents[3]
    return proyectos / "gei-web" / "src" / "storage" / "app" / "private" / "liquidaciones"


def cargar_config() -> Config:
    base = os.environ.get("GEI_LARAVEL_LIQUIDACIONES_DIR")
    importador_base = os.environ.get("GEI_IMPORTADOR_BASE_DIR")
    repo_base = Path(__file__).resolve().parents[2]

    return Config(
        laravel_liquidaciones_dir=Path(base).expanduser()
        if base
        else _default_laravel_liquidaciones_dir(),
        pghost=os.environ.get("PGHOST", "127.0.0.1"),
        pgport=int(os.environ.get("PGPORT", "5432")),
        pgdatabase=os.environ.get("PGDATABASE", ""),
        pguser=os.environ.get("PGUSER", ""),
        pgpassword=os.environ.get("PGPASSWORD", ""),
        importador_base_dir=Path(importador_base).expanduser()
        if importador_base
        else repo_base,
    )
