#!/usr/bin/env python3
"""Exporta un DBF (dBase/Visual FoxPro) a JSONL sin dependencias externas.

El archivo de origen se abre exclusivamente en modo binario de lectura. Cada línea
JSON contiene número de registro, marca de borrado y los campos del registro.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import math
import os
import struct
import sys
from decimal import Decimal
from pathlib import Path
from typing import Any


def decode_text(raw: bytes, encoding: str) -> str:
    raw = raw.rstrip(b"\x00 ")
    if not raw:
        return ""
    try:
        return raw.decode(encoding)
    except UnicodeDecodeError:
        return raw.decode(encoding, errors="replace")


def parse_value(raw: bytes, field_type: str, encoding: str) -> Any:
    if field_type in {"C", "V", "Q"}:
        return decode_text(raw, encoding)

    if field_type in {"N", "F"}:
        text = raw.decode("ascii", errors="ignore").strip()
        return text if text else None

    if field_type == "D":
        text = raw.decode("ascii", errors="ignore").strip()
        if len(text) == 8 and text.isdigit():
            return f"{text[0:4]}-{text[4:6]}-{text[6:8]}"
        return text or None

    if field_type == "L":
        text = raw[:1].upper()
        if text in {b"Y", b"T"}:
            return True
        if text in {b"N", b"F"}:
            return False
        return None

    if field_type == "I" and len(raw) >= 4:
        return struct.unpack("<i", raw[:4])[0]

    if field_type == "Y" and len(raw) >= 8:
        value = Decimal(struct.unpack("<q", raw[:8])[0]) / Decimal(10000)
        return format(value, "f")

    if field_type == "B" and len(raw) >= 8:
        value = struct.unpack("<d", raw[:8])[0]
        return value if math.isfinite(value) else None

    if field_type == "T" and len(raw) >= 8:
        julian, millis = struct.unpack("<ii", raw[:8])
        # Visual FoxPro usa día juliano + milisegundos desde medianoche.
        if julian > 0 and 0 <= millis < 86_400_000:
            # Conversión de Julian Day Number a fecha gregoriana.
            l = julian + 68569
            n = 4 * l // 146097
            l = l - (146097 * n + 3) // 4
            i = 4000 * (l + 1) // 1461001
            l = l - 1461 * i // 4 + 31
            j = 80 * l // 2447
            day = l - 2447 * j // 80
            l = j // 11
            month = j + 2 - 12 * l
            year = 100 * (n - 49) + i + l
            try:
                base = dt.datetime(year, month, day)
                return (base + dt.timedelta(milliseconds=millis)).isoformat(sep=" ")
            except ValueError:
                pass
        return raw.hex()

    if field_type in {"M", "G", "P"}:
        # Puntero a memo/general. Se conserva el valor del puntero aunque no exista FPT.
        if len(raw) >= 4:
            return struct.unpack("<I", raw[:4])[0]
        return raw.hex()

    # Campo desconocido: preservar el contenido sin abortar la importación.
    text = decode_text(raw, encoding)
    return text if text else raw.hex()


def read_header(fh, encoding: str) -> dict[str, Any]:
    header = fh.read(32)
    if len(header) != 32:
        raise RuntimeError("Cabecera DBF incompleta")

    version = header[0]
    record_count = struct.unpack("<I", header[4:8])[0]
    header_length = struct.unpack("<H", header[8:10])[0]
    record_length = struct.unpack("<H", header[10:12])[0]

    fields: list[dict[str, Any]] = []
    while fh.tell() < header_length:
        first = fh.read(1)
        if not first:
            raise RuntimeError("Descriptor de campos DBF incompleto")
        if first == b"\r":
            break
        rest = fh.read(31)
        if len(rest) != 31:
            raise RuntimeError("Descriptor de campo DBF incompleto")
        desc = first + rest
        name = desc[0:11].split(b"\x00", 1)[0].decode("ascii", errors="replace").strip()
        field_type = chr(desc[11])
        length = desc[16]
        decimals = desc[17]
        flags = desc[18]
        if not name:
            name = f"CAMPO_{len(fields) + 1}"
        fields.append({
            "name": name,
            "type": field_type,
            "length": length,
            "decimals": decimals,
            "flags": flags,
        })

    return {
        "version": version,
        "record_count_header": record_count,
        "header_length": header_length,
        "record_length": record_length,
        "encoding": encoding,
        "fields": fields,
    }


def write_progress(progress_path: Path | None, stage: str, detail: str, processed: int, total: int) -> None:
    if progress_path is None:
        return
    progress_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "estado": "PROCESANDO",
        "etapa": stage,
        "detalle": detail,
        "procesados": processed,
        "total": total,
        "porcentaje": round((processed * 100 / total), 2) if total else 0,
        "actualizado_at": dt.datetime.now().isoformat(sep=" "),
    }
    tmp = progress_path.with_suffix(progress_path.suffix + ".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    os.replace(tmp, progress_path)


def export_dbf(input_path: Path, output_path: Path, metadata_path: Path, encoding: str, progress_path: Path | None = None, stage: str = "EXPORTANDO_DBF") -> dict[str, Any]:
    source_stat = input_path.stat()
    exported = 0
    deleted = 0

    with input_path.open("rb") as fh:
        metadata = read_header(fh, encoding)
        fh.seek(metadata["header_length"])

        total_records = int(metadata["record_count_header"])
        write_progress(progress_path, stage, f"Leyendo {input_path.name}", 0, total_records)

        output_path.parent.mkdir(parents=True, exist_ok=True)
        with output_path.open("w", encoding="utf-8", newline="\n") as out:
            for recno in range(1, metadata["record_count_header"] + 1):
                record = fh.read(metadata["record_length"])
                if len(record) == 0:
                    break
                if len(record) != metadata["record_length"]:
                    raise RuntimeError(
                        f"Registro {recno} incompleto: {len(record)} bytes, se esperaban {metadata['record_length']}"
                    )

                deleted_flag = record[0:1] == b"*"
                if deleted_flag:
                    deleted += 1

                offset = 1
                data: dict[str, Any] = {}
                for field in metadata["fields"]:
                    length = int(field["length"])
                    raw = record[offset:offset + length]
                    offset += length
                    data[field["name"]] = parse_value(raw, field["type"], encoding)

                out.write(json.dumps({
                    "registro": recno,
                    "eliminado": deleted_flag,
                    "datos": data,
                }, ensure_ascii=False, separators=(",", ":")))
                out.write("\n")
                exported += 1
                if exported == 1 or exported % 1000 == 0 or exported == total_records:
                    write_progress(progress_path, stage, f"Leyendo {input_path.name}", exported, total_records)

    metadata.update({
        "source": str(input_path),
        "source_size": source_stat.st_size,
        "source_mtime": dt.datetime.fromtimestamp(source_stat.st_mtime).isoformat(sep=" "),
        "records_exported": exported,
        "records_deleted": deleted,
    })

    metadata_path.parent.mkdir(parents=True, exist_ok=True)
    metadata_path.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
    return metadata


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--metadata", required=True)
    parser.add_argument("--encoding", default="cp1252")
    parser.add_argument("--progress-file")
    parser.add_argument("--stage", default="EXPORTANDO_DBF")
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.is_file():
        print(json.dumps({"ok": False, "error": f"No existe el DBF: {input_path}"}, ensure_ascii=False))
        return 2

    try:
        metadata = export_dbf(
            input_path,
            Path(args.output),
            Path(args.metadata),
            args.encoding,
            Path(args.progress_file) if args.progress_file else None,
            args.stage,
        )
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False))
        return 1

    print(json.dumps({
        "ok": True,
        "source": str(input_path),
        "records": metadata["records_exported"],
        "deleted": metadata["records_deleted"],
        "fields": len(metadata["fields"]),
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
