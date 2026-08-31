from __future__ import annotations

from gei_importador.cobol.importador import ImportadorCobol
from gei_importador.cobol.comparador import comparar_cobol as comparar_cobol_postgresql
from gei_importador.config import Config
from gei_importador.repositories.importaciones import RepositorioImportacionesLaravel
from gei_importador.resultado import ResultadoImportacion


def importar_cobol(
    config: Config,
    repositorio_id: int,
    modo: str,
    planificar_maestros: bool = False,
) -> ResultadoImportacion:
    repositorios = RepositorioImportacionesLaravel(config.laravel_liquidaciones_dir)
    repositorio = repositorios.obtener(repositorio_id)

    return ImportadorCobol(repositorio, config=config).importar(
        modo,
        planificar_maestros=planificar_maestros,
    )


def comparar_cobol(
    config: Config,
    repositorio_id: int,
) -> ResultadoImportacion:
    repositorios = RepositorioImportacionesLaravel(config.laravel_liquidaciones_dir)
    repositorio = repositorios.obtener(repositorio_id)

    return comparar_cobol_postgresql(config, repositorio)
