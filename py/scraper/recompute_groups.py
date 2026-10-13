"""
Recalcula pro_grupo con la lógica actual del pipeline
(modelo + capacidad; color = variante).
"""
from pipelines import MySQLPipeline


def main():
    pipe = MySQLPipeline()
    pipe.open_spider()

    pipe.cursor.execute(
        """
        SELECT idproductos, pro_nombre, pro_marca, pro_grupo
        FROM productos
        """
    )
    rows = pipe.cursor.fetchall()

    updated = 0
    samples = []

    for product_id, nombre, marca, old_grupo in rows:
        new_grupo = pipe.format_group_name(
            pipe.extract_base_model(nombre or "", marca or "")
        )
        old = (old_grupo or "").strip()
        if not new_grupo or new_grupo == old:
            continue

        pipe.cursor.execute(
            """
            UPDATE productos
            SET pro_grupo = %s
            WHERE idproductos = %s
            """,
            (new_grupo, product_id),
        )
        updated += 1
        if len(samples) < 12:
            samples.append((old, new_grupo, nombre))

    pipe.close_spider()

    print(f"Actualizados: {updated} / {len(rows)}")
    for old, new, nombre in samples:
        safe = (nombre or "").encode("ascii", "replace").decode("ascii")
        print(f"- [{old}] -> [{new}] | {safe}")


if __name__ == "__main__":
    main()
