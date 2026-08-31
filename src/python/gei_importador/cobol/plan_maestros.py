from __future__ import annotations

import csv
import json
from collections import Counter
from dataclasses import asdict, dataclass
from datetime import date, datetime
from hashlib import sha256
from pathlib import Path
from typing import Any, Protocol

from gei_importador.cobol.claves import claves_inquilino, claves_propietario
from gei_importador.config import Config
from gei_importador.database import conectar
from gei_importador.models import InquilinoCobol, PropietarioCobol
from gei_importador.cobol.auditoria_plan_maestros import auditar_plan_maestros
from gei_importador.cobol.reclasificacion_plan_maestros_fox import (
    reclasificar_plan_maestros_fox,
)


ACCIONES = {
    "INSERTAR",
    "ACTUALIZAR",
    "OMITIR_EXISTENTE",
    "CONFLICTO",
    "ERROR_VALIDACION",
}


@dataclass(frozen=True)
class ExistentePostgresql:
    tabla: str
    clave_postgresql: str
    campos: dict[str, Any]


class RepositorioPlanMaestros(Protocol):
    def buscar(
        self,
        entidad: str,
        clave_funcional: str,
        datos: dict[str, Any],
    ) -> ExistentePostgresql | None:
        ...


@dataclass(frozen=True)
class ItemPlanMaestro:
    entidad: str
    clave_funcional: str
    tabla_destino: str
    accion: str
    hash_entidad: str
    campos_fuente: dict[str, Any]
    campos_postgresql: dict[str, Any] | None
    diferencias: list[dict[str, Any]]
    motivo: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


class PlanificadorMaestrosCobol:
    def __init__(self, repositorio: RepositorioPlanMaestros) -> None:
        self.repositorio = repositorio

    def planificar(
        self,
        propietarios: list[PropietarioCobol],
        inquilinos: list[InquilinoCobol],
    ) -> list[ItemPlanMaestro]:
        items: list[ItemPlanMaestro] = []

        for propietario in propietarios:
            items.append(self._plan_propietario_cliente(propietario))

        for inquilino in inquilinos:
            items.extend(self._plan_inquilino(inquilino))

        return items

    def _plan_propietario_cliente(
        self,
        registro: PropietarioCobol,
    ) -> ItemPlanMaestro:
        datos = _datos_propietario_cliente(registro)
        return self._item(
            "propietario_cliente",
            claves_propietario(registro).propietario_cliente,
            "clientes",
            datos,
        )

    def _plan_inquilino(self, registro: InquilinoCobol) -> list[ItemPlanMaestro]:
        claves = claves_inquilino(registro)
        datos_cliente = _datos_inquilino_cliente(registro)
        datos_inmueble = _datos_inmueble(registro)
        datos_contrato = _datos_contrato(registro)

        return [
            self._item(
                "inquilino_cliente",
                claves.inquilino_cliente,
                "clientes",
                datos_cliente,
            ),
            self._item("inmueble", claves.inmueble, "inmuebles", datos_inmueble),
            self._item("contrato", claves.contrato, "contratos", datos_contrato),
            self._item(
                "inmueble_propietario",
                claves.inmueble_propietario,
                "inmuebles_propietarios",
                _datos_inmueble_propietario(registro, claves.inmueble),
            ),
            self._item(
                "contrato_inquilino",
                claves.contrato_inquilino,
                "contratos_inquilinos",
                _datos_contrato_inquilino(registro, claves.contrato),
            ),
            self._item(
                "contrato_inmueble",
                claves.contrato_inmueble,
                "contratos_inmuebles",
                _datos_contrato_inmueble(registro, claves.contrato, claves.inmueble),
            ),
        ]

    def _item(
        self,
        entidad: str,
        clave_funcional: str,
        tabla_destino: str,
        datos: dict[str, Any],
    ) -> ItemPlanMaestro:
        try:
            existente = self.repositorio.buscar(entidad, clave_funcional, datos)
        except Exception as exc:
            return ItemPlanMaestro(
                entidad=entidad,
                clave_funcional=clave_funcional,
                tabla_destino=tabla_destino,
                accion="ERROR_VALIDACION",
                hash_entidad=hash_entidad(datos),
                campos_fuente=datos,
                campos_postgresql=None,
                diferencias=[],
                motivo=f"Error consultando PostgreSQL: {type(exc).__name__}: {exc}",
            )

        if existente is None:
            return ItemPlanMaestro(
                entidad=entidad,
                clave_funcional=clave_funcional,
                tabla_destino=tabla_destino,
                accion="INSERTAR",
                hash_entidad=hash_entidad(datos),
                campos_fuente=datos,
                campos_postgresql=None,
                diferencias=[],
                motivo="No se encontro registro equivalente en PostgreSQL.",
            )

        diferencias = comparar_campos(datos, existente.campos)
        if not diferencias:
            return ItemPlanMaestro(
                entidad=entidad,
                clave_funcional=clave_funcional,
                tabla_destino=tabla_destino,
                accion="OMITIR_EXISTENTE",
                hash_entidad=hash_entidad(datos),
                campos_fuente=datos,
                campos_postgresql=existente.campos,
                diferencias=[],
                motivo="El registro existente coincide en los campos comparados.",
            )

        accion = (
            "CONFLICTO"
            if _tiene_diferencia_sensible(entidad, diferencias)
            else "ACTUALIZAR"
        )
        motivo = (
            "Existen diferencias en campos sensibles; no resolver automaticamente."
            if accion == "CONFLICTO"
            else "Existen diferencias no sensibles; quedaria como actualizacion propuesta."
        )

        return ItemPlanMaestro(
            entidad=entidad,
            clave_funcional=clave_funcional,
            tabla_destino=tabla_destino,
            accion=accion,
            hash_entidad=hash_entidad(datos),
            campos_fuente=datos,
            campos_postgresql=existente.campos,
            diferencias=diferencias,
            motivo=motivo,
        )


class RepositorioMaestrosPostgresql:
    def __init__(self, config: Config) -> None:
        self.config = config
        self.clientes_por_cuit: dict[str, dict[str, Any]] = {}
        self.clientes_por_docnro: dict[str, dict[str, Any]] = {}
        self.clientes_por_id_prop: dict[str, dict[str, Any]] = {}
        self.clientes_por_id_inq: dict[str, dict[str, Any]] = {}
        self.inmuebles_por_domicilio: dict[str, dict[str, Any]] = {}
        self.inmuebles_propietarios: set[tuple[int, int, str]] = set()
        self.contratos_por_cliente_id_inq: dict[tuple[int, str], dict[str, Any]] = {}
        self.contratos_inmuebles: set[tuple[int, int]] = set()
        self._cargar()

    def buscar(
        self,
        entidad: str,
        clave_funcional: str,
        datos: dict[str, Any],
    ) -> ExistentePostgresql | None:
        if entidad in {"propietario_cliente", "inquilino_cliente"}:
            cliente = self._buscar_cliente(datos)
            if cliente is None:
                return None
            return ExistentePostgresql(
                tabla="clientes",
                clave_postgresql=str(cliente["codigo_cliente"]),
                campos=_campos_cliente_para_comparar(cliente, datos),
            )

        if entidad == "inmueble":
            inmueble = self.inmuebles_por_domicilio.get(
                _normalizar_texto(datos["domicilio_calle"])
            )
            if inmueble is None:
                return None
            return ExistentePostgresql(
                tabla="inmuebles",
                clave_postgresql=str(inmueble["codigo_inmueble"]),
                campos={
                    "domicilio_calle": _normalizar_texto(inmueble["domicilio_calle"]),
                    "pais": _normalizar_texto(inmueble["pais"]),
                    "provincia": _normalizar_texto(inmueble["provincia"]),
                    "localidad": _normalizar_texto(inmueble["localidad"]),
                    "cp": str(inmueble["cp"]).strip(),
                },
            )

        if entidad == "contrato":
            cliente = self._buscar_cliente(datos)
            if cliente is None:
                return None
            contrato = self.contratos_por_cliente_id_inq.get(
                (int(cliente["codigo_cliente"]), str(datos["_id_inq"]))
            )
            if contrato is None:
                return None
            return ExistentePostgresql(
                tabla="contratos",
                clave_postgresql=str(contrato["codigo_contrato"]),
                campos={
                    "fecha_contrato": _fecha_iso(contrato["fecha_contrato"]),
                    "fecha_inicio": _fecha_iso(contrato["fecha_inicio"]),
                    "fecha_fin": _fecha_iso(contrato["fecha_fin"]),
                    "plazo": int(contrato["plazo"] or 0),
                    "importe_inicial": str(contrato["importe_inicial"] or "0"),
                    "observaciones": str(contrato["observaciones"] or "").strip(),
                    "numero_de_contrato": _contrato_vacio(
                        contrato["numero_de_contrato"]
                    ),
                },
            )

        if entidad == "inmueble_propietario":
            cliente = self._buscar_cliente({"_id_prop": datos["_id_prop"]})
            inmueble = self.inmuebles_por_domicilio.get(
                _normalizar_texto(datos["_domicilio_calle"])
            )
            if cliente is None or inmueble is None:
                return None
            clave = (
                int(inmueble["codigo_inmueble"]),
                int(cliente["codigo_cliente"]),
                str(datos["_id_prop"]),
            )
            if clave not in self.inmuebles_propietarios:
                return None
            return ExistentePostgresql(
                tabla="inmuebles_propietarios",
                clave_postgresql="|".join(map(str, clave)),
                campos=datos,
            )

        if entidad == "contrato_inquilino":
            cliente = self._buscar_cliente(datos)
            if cliente is None:
                return None
            contrato = self.contratos_por_cliente_id_inq.get(
                (int(cliente["codigo_cliente"]), str(datos["_id_inq"]))
            )
            if contrato is None:
                return None
            return ExistentePostgresql(
                tabla="contratos_inquilinos",
                clave_postgresql=(
                    f"{contrato['codigo_contrato']}|{cliente['codigo_cliente']}"
                ),
                campos=datos,
            )

        if entidad == "contrato_inmueble":
            cliente = self._buscar_cliente(datos)
            contrato = None
            if cliente is not None:
                contrato = self.contratos_por_cliente_id_inq.get(
                    (int(cliente["codigo_cliente"]), str(datos["_id_inq"]))
                )
            inmueble = self.inmuebles_por_domicilio.get(
                _normalizar_texto(datos["_domicilio_calle"])
            )
            if contrato is None or inmueble is None:
                return None
            clave = (int(contrato["codigo_contrato"]), int(inmueble["codigo_inmueble"]))
            if clave not in self.contratos_inmuebles:
                return None
            return ExistentePostgresql(
                tabla="contratos_inmuebles",
                clave_postgresql=f"{clave[0]}|{clave[1]}",
                campos=datos,
            )

        return None

    def _buscar_cliente(self, datos: dict[str, Any]) -> dict[str, Any] | None:
        cuit = str(datos.get("cuit", datos.get("_cuit", ""))).strip()
        docnro = str(datos.get("docnro", datos.get("_docnro", ""))).strip()
        id_prop = str(datos.get("id_prop", datos.get("_id_prop", "0"))).strip()
        id_inq = str(datos.get("id_inq", datos.get("_id_inq", "0"))).strip()

        if cuit and cuit in self.clientes_por_cuit:
            return self.clientes_por_cuit[cuit]
        if docnro and docnro in self.clientes_por_docnro:
            return self.clientes_por_docnro[docnro]
        if id_prop not in {"", "0"} and id_prop in self.clientes_por_id_prop:
            return self.clientes_por_id_prop[id_prop]
        if id_inq not in {"", "0"} and id_inq in self.clientes_por_id_inq:
            return self.clientes_por_id_inq[id_inq]
        return None

    def _buscar_contrato_por_clave(self, contrato_clave: str) -> dict[str, Any] | None:
        for contrato in self.contratos_por_cliente_id_inq.values():
            if contrato.get("clave_funcional") == contrato_clave:
                return contrato
        return None

    def _cargar(self) -> None:
        with conectar(self.config) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT codigo_cliente, doctipo, docnro, apellidos, nombres,
                           domicilio, provincia, localidad, cp, caractel,
                           telefonos, email, cuit, condicion_iva, personeria,
                           id_prop, id_inq, razon_social
                    FROM clientes
                    """
                )
                cols = [desc.name for desc in cur.description]
                for row in cur.fetchall():
                    cliente = dict(zip(cols, row))
                    cuit = str(cliente["cuit"]).strip()
                    docnro = str(cliente["docnro"]).strip()
                    id_prop = str(cliente["id_prop"]).split(".")[0]
                    id_inq = str(cliente["id_inq"]).split(".")[0]
                    if cuit:
                        self.clientes_por_cuit.setdefault(cuit, cliente)
                    if docnro:
                        self.clientes_por_docnro.setdefault(docnro, cliente)
                    if id_prop not in {"", "0"}:
                        self.clientes_por_id_prop.setdefault(id_prop, cliente)
                    if id_inq not in {"", "0"}:
                        self.clientes_por_id_inq.setdefault(id_inq, cliente)

                cur.execute(
                    """
                    SELECT codigo_inmueble, domicilio_calle, pais, provincia,
                           localidad, cp
                    FROM inmuebles
                    """
                )
                cols = [desc.name for desc in cur.description]
                for row in cur.fetchall():
                    inmueble = dict(zip(cols, row))
                    self.inmuebles_por_domicilio.setdefault(
                        _normalizar_texto(inmueble["domicilio_calle"]),
                        inmueble,
                    )

                cur.execute(
                    """
                    SELECT codigo_inmueble, codigo_cliente, id_prop
                    FROM inmuebles_propietarios
                    """
                )
                for codigo_inmueble, codigo_cliente, id_prop in cur.fetchall():
                    self.inmuebles_propietarios.add(
                        (
                            int(codigo_inmueble),
                            int(codigo_cliente),
                            str(id_prop).split(".")[0],
                        )
                    )

                cur.execute(
                    """
                    SELECT ci.codigo_cliente, ci.id_inq, c.codigo_contrato,
                           c.fecha_contrato, c.fecha_inicio, c.fecha_fin,
                           c.plazo, c.importe_inicial, c.observaciones,
                           c.numero_de_contrato
                    FROM contratos_inquilinos ci
                    JOIN contratos c ON c.codigo_contrato = ci.codigo_contrato
                    """
                )
                cols = [desc.name for desc in cur.description]
                for row in cur.fetchall():
                    contrato = dict(zip(cols, row))
                    key = (
                        int(contrato["codigo_cliente"]),
                        str(contrato["id_inq"]).split(".")[0],
                    )
                    self.contratos_por_cliente_id_inq.setdefault(key, contrato)

                cur.execute("SELECT codigo_contrato, codigo_inmueble FROM contratos_inmuebles")
                for codigo_contrato, codigo_inmueble in cur.fetchall():
                    self.contratos_inmuebles.add((int(codigo_contrato), int(codigo_inmueble)))


def guardar_plan_maestros(
    items: list[ItemPlanMaestro],
    directorio: Path,
) -> dict[str, Any]:
    directorio.mkdir(parents=True, exist_ok=True)
    ruta_json = directorio / "plan_maestros.json"
    ruta_csv = directorio / "plan_maestros.csv"
    filas = [item.to_dict() for item in items]

    ruta_json.write_text(
        json.dumps(filas, ensure_ascii=False, indent=2, default=str),
        encoding="utf-8",
    )

    with ruta_csv.open("w", encoding="utf-8", newline="") as handle:
        fieldnames = [
            "entidad",
            "clave_funcional",
            "tabla_destino",
            "accion",
            "hash_entidad",
            "motivo",
            "diferencias",
        ]
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for fila in filas:
            writer.writerow(
                {
                    "entidad": fila["entidad"],
                    "clave_funcional": fila["clave_funcional"],
                    "tabla_destino": fila["tabla_destino"],
                    "accion": fila["accion"],
                    "hash_entidad": fila["hash_entidad"],
                    "motivo": fila["motivo"],
                    "diferencias": json.dumps(
                        fila["diferencias"],
                        ensure_ascii=False,
                        default=str,
                    ),
                }
            )

    auditoria = auditar_plan_maestros(ruta_json, directorio)
    plan_fox = reclasificar_plan_maestros_fox(ruta_json, directorio)

    return {
        "json": str(ruta_json),
        "csv": str(ruta_csv),
        "resumen": resumen_plan(items),
        "auditoria": {
            "resumen_entidad_json": str(auditoria.resumen_entidad_json),
            "resumen_entidad_csv": str(auditoria.resumen_entidad_csv),
            "conflictos_resumen_json": str(auditoria.conflictos_resumen_json),
            "conflictos_resumen_csv": str(auditoria.conflictos_resumen_csv),
            "conflictos_detalle_json": str(auditoria.conflictos_detalle_json),
            "conflictos_detalle_csv": str(auditoria.conflictos_detalle_csv),
            "insertar_sospechosos_json": str(auditoria.insertar_sospechosos_json),
            "insertar_sospechosos_csv": str(auditoria.insertar_sospechosos_csv),
        },
        "plan_fox": {
            "json": str(plan_fox.plan_json),
            "csv": str(plan_fox.plan_csv),
            "resumen_json": str(plan_fox.resumen_json),
            "resumen_csv": str(plan_fox.resumen_csv),
        },
    }


def resumen_plan(items: list[ItemPlanMaestro]) -> dict[str, Any]:
    resumen: dict[str, Any] = {}
    por_entidad = Counter(item.entidad for item in items)
    for entidad, cantidad in sorted(por_entidad.items()):
        subset = [item for item in items if item.entidad == entidad]
        acciones = Counter(item.accion for item in subset)
        resumen[entidad] = {
            "fuente": cantidad,
            "existentes": sum(
                1 for item in subset if item.campos_postgresql is not None
            ),
            "a_insertar": acciones.get("INSERTAR", 0),
            "a_actualizar": acciones.get("ACTUALIZAR", 0),
            "omitidos": acciones.get("OMITIR_EXISTENTE", 0),
            "conflictos": acciones.get("CONFLICTO", 0),
            "errores": acciones.get("ERROR_VALIDACION", 0),
        }

    return resumen


def hash_entidad(datos: dict[str, Any]) -> str:
    normalizado = json.dumps(
        _normalizar(datos),
        ensure_ascii=False,
        sort_keys=True,
        default=str,
    )
    return sha256(normalizado.encode("utf-8")).hexdigest()


def comparar_campos(
    fuente: dict[str, Any],
    postgresql: dict[str, Any],
) -> list[dict[str, Any]]:
    diferencias: list[dict[str, Any]] = []
    for campo, valor in fuente.items():
        if campo.startswith("_"):
            continue
        actual = _normalizar(valor)
        existente = _normalizar(postgresql.get(campo))
        if actual == existente:
            continue
        diferencias.append(
            {
                "campo": campo,
                "valor_fuente": actual,
                "valor_postgresql": existente,
            }
        )
    return diferencias


def _datos_propietario_cliente(registro: PropietarioCobol) -> dict[str, Any]:
    cuit = _cuit(registro.identificacion_fiscal)
    return {
        "id_prop": registro.cuenta,
        "id_inq": "0",
        "cuit": cuit,
        "docnro": cuit[3:11] if cuit else "",
        "doctipo": "DNI" if cuit else "",
        "razon_social": _razon_social(registro.nombre, registro.personeria_fiscal),
        "apellidos": _proper(registro.nombre),
        "nombres": "",
        "domicilio": _proper(registro.domicilio),
        "provincia": _provincia_por_cp(registro.codigo_postal, registro.provincia),
        "localidad": _localidad_por_cp(registro.codigo_postal, registro.localidad),
        "cp": str(registro.codigo_postal).strip(),
        "caractel": "342" if str(registro.codigo_postal).strip() == "3000" else "",
        "telefonos": registro.telefono.strip(),
        "email": "",
        "nacionalidad": "Argentina",
        "condicion_iva": _condicion_iva_propietario(registro.personeria_fiscal),
        "personeria": "Física",
    }


def _datos_inquilino_cliente(registro: InquilinoCobol) -> dict[str, Any]:
    cuit = _cuit(registro.identificacion_fiscal)
    docnro = str(registro.documento).strip()
    if len(docnro) > 8:
        docnro = docnro[-8:]
    apellidos, nombres = _separar_nombre(_proper(registro.nombre))
    return {
        "id_prop": registro.cuenta_propietario,
        "id_inq": registro.cuenta,
        "cuit": cuit,
        "docnro": "" if cuit else docnro,
        "doctipo": "" if cuit else _tipo_documento(registro.tipo_documento),
        "razon_social": _razon_social(registro.nombre, registro.personeria_fiscal),
        "apellidos": apellidos,
        "nombres": nombres,
        "domicilio": _proper(registro.domicilio_legal),
        "provincia": _provincia_por_cp(str(registro.codigo_postal), registro.provincia),
        "localidad": _localidad_por_cp(str(registro.codigo_postal), registro.localidad),
        "cp": str(registro.codigo_postal).strip(),
        "caractel": "342" if str(registro.codigo_postal).strip() == "3000" else "",
        "telefonos": " / ".join(
            v
            for v in [
                registro.telefono_particular.strip(),
                registro.telefono_laboral.strip(),
            ]
            if v
        ),
        "email": "",
        "nacionalidad": "Argentina",
        "condicion_iva": _condicion_iva_inquilino(registro.personeria_fiscal),
        "personeria": "Física",
    }


def _datos_inmueble(registro: InquilinoCobol) -> dict[str, Any]:
    return {
        "domicilio_calle": _normalizar_texto(registro.domicilio_inmueble),
        "pais": "ARGENTINA",
        "provincia": _normalizar_texto(
            _provincia_por_cp(str(registro.codigo_postal), registro.provincia)
        ),
        "localidad": _normalizar_texto(
            _localidad_por_cp(str(registro.codigo_postal), registro.localidad)
        ),
        "cp": str(registro.codigo_postal).strip(),
    }


def _datos_contrato(registro: InquilinoCobol) -> dict[str, Any]:
    return {
        "_id_inq": registro.cuenta,
        "_id_prop": registro.cuenta_propietario,
        "_cuit": _cuit(registro.identificacion_fiscal),
        "_docnro": str(registro.documento).strip()[-8:],
        "fecha_contrato": _fecha_iso(registro.fecha_contrato),
        "fecha_inicio": _fecha_iso(registro.fecha_inicio),
        "fecha_fin": _fecha_iso(registro.fecha_vencimiento or registro.fecha_inicio),
        "plazo": 0,
        "importe_inicial": "0",
        "observaciones": "",
        "numero_de_contrato": "",
    }


def _datos_inmueble_propietario(
    registro: InquilinoCobol,
    inmueble_clave: str,
) -> dict[str, Any]:
    return {
        "_id_prop": registro.cuenta_propietario,
        "inmueble_clave": inmueble_clave,
        "_domicilio_calle": _normalizar_texto(registro.domicilio_inmueble),
        "porcentaje_titularidad": "100",
    }


def _datos_contrato_inquilino(
    registro: InquilinoCobol,
    contrato_clave: str,
) -> dict[str, Any]:
    return {
        "contrato_clave": contrato_clave,
        "_id_inq": registro.cuenta,
        "_id_prop": registro.cuenta_propietario,
        "_cuit": _cuit(registro.identificacion_fiscal),
        "_docnro": str(registro.documento).strip()[-8:],
        "porcentaje_participacion": "100",
    }


def _datos_contrato_inmueble(
    registro: InquilinoCobol,
    contrato_clave: str,
    inmueble_clave: str,
) -> dict[str, Any]:
    domicilio = inmueble_clave.split("|")[2] if "|" in inmueble_clave else inmueble_clave
    return {
        "contrato_clave": contrato_clave,
        "inmueble_clave": inmueble_clave,
        "_id_inq": registro.cuenta,
        "_id_prop": registro.cuenta_propietario,
        "_cuit": _cuit(registro.identificacion_fiscal),
        "_docnro": str(registro.documento).strip()[-8:],
        "_domicilio_calle": domicilio,
    }


def _datos_propietario_minimo(id_prop: str) -> dict[str, Any]:
    return {"id_prop": id_prop, "id_inq": "0", "cuit": "", "docnro": ""}


def _campos_cliente_para_comparar(
    cliente: dict[str, Any],
    fuente: dict[str, Any],
) -> dict[str, Any]:
    campos = {
        "id_prop": str(cliente.get("id_prop", "0")).split(".")[0],
        "id_inq": str(cliente.get("id_inq", "0")).split(".")[0],
        "cuit": str(cliente.get("cuit", "")).strip(),
        "docnro": str(cliente.get("docnro", "")).strip(),
        "doctipo": str(cliente.get("doctipo", "")).strip(),
        "razon_social": _normalizar_texto(cliente.get("razon_social", "")),
        "apellidos": _normalizar_texto(cliente.get("apellidos", "")),
        "nombres": _normalizar_texto(cliente.get("nombres", "")),
        "domicilio": _normalizar_texto(cliente.get("domicilio", "")),
        "provincia": _normalizar_texto(cliente.get("provincia", "")),
        "localidad": _normalizar_texto(cliente.get("localidad", "")),
        "cp": str(cliente.get("cp", "")).strip(),
        "caractel": str(cliente.get("caractel", "")).strip(),
        "telefonos": str(cliente.get("telefonos", "")).strip(),
        "email": str(cliente.get("email", "")).strip(),
        "nacionalidad": _normalizar_texto(cliente.get("nacionalidad", "")),
        "condicion_iva": str(cliente.get("condicion_iva", "")).strip(),
        "personeria": str(cliente.get("personeria", "")).strip(),
    }
    return {key: campos.get(key) for key in fuente if key in campos}


def _tiene_diferencia_sensible(
    entidad: str,
    diferencias: list[dict[str, Any]],
) -> bool:
    sensibles = {
        "cuit",
        "docnro",
        "doctipo",
        "condicion_iva",
        "personeria",
        "id_prop",
        "id_inq",
        "fecha_contrato",
        "fecha_inicio",
        "fecha_fin",
    }
    if entidad in {"contrato", "contrato_inquilino", "contrato_inmueble"}:
        return True
    return any(d["campo"] in sensibles for d in diferencias)


def _normalizar(value: Any) -> Any:
    if isinstance(value, (date, datetime)):
        return value.isoformat()
    if isinstance(value, str):
        return value.strip()
    return value


def _contrato_vacio(value: Any) -> str:
    texto = str(value or "").strip()
    return "" if texto in {"0", "0.0"} else texto


def _normalizar_texto(value: Any) -> str:
    return " ".join(str(value or "").strip().upper().split())


def _proper(value: str) -> str:
    return " ".join(part.capitalize() for part in value.strip().split())


def _separar_nombre(value: str) -> tuple[str, str]:
    if " " not in value:
        return value, ""
    apellido, nombres = value.split(" ", 1)
    return apellido.strip(), nombres.strip()


def _fecha_iso(value: Any) -> str:
    if value is None:
        return "1900-01-01"
    if isinstance(value, (date, datetime)):
        return value.date().isoformat() if isinstance(value, datetime) else value.isoformat()
    return str(value)[:10]


def _cuit(value: int) -> str:
    if not value:
        return ""
    digits = str(int(value)).zfill(11)
    return f"{digits[:2]}-{digits[2:10]}-{digits[10:]}"


def _tipo_documento(value: int) -> str:
    return {1: "LE", 2: "LC", 3: "DNI"}.get(value, "")


def _condicion_iva_inquilino(value: int) -> str:
    return {
        1: "Responsable Inscripto",
        3: "Consumidor Final",
        4: "Exento",
        5: "Responsable Monotributo",
        6: "Sujeto no Categorizado",
    }.get(value, "Consumidor Final")


def _condicion_iva_propietario(value: int) -> str:
    return {
        1: "Responsable Inscripto",
        3: "Exento",
        4: "Consumidor Final",
        5: "Responsable Monotributo",
        6: "Sujeto no Categorizado",
    }.get(value, "Consumidor Final")


def _razon_social(nombre: str, personeria_fiscal: int) -> str:
    return _proper(nombre) if personeria_fiscal in {1, 3, 5, 6} else ""


def _provincia_por_cp(cp: str, provincia: str) -> str:
    return "Santa Fe" if str(cp).strip() == "3000" else _proper(provincia)


def _localidad_por_cp(cp: str, localidad: str) -> str:
    return "Santa Fe" if str(cp).strip() == "3000" else _proper(localidad)
