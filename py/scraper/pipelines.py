import pymysql
import re
import unicodedata
from itemadapter import ItemAdapter


class MySQLPipeline:
    def open_spider(self, spider=None):
        self.connection = pymysql.connect(
            host="localhost",
            user="root",
            password="",
            database="Caacuprecio",
            charset="utf8mb4",
            autocommit=True,
            cursorclass=pymysql.cursors.Cursor
        )
        self.cursor = self.connection.cursor()

    def close_spider(self, spider=None):
        if getattr(self, "cursor", None):
            self.cursor.close()
        if getattr(self, "connection", None):
            self.connection.close()

    def process_item(self, item, spider=None):
        adapter = ItemAdapter(item)

        nombre = self.clean_text(adapter.get("nombre"))
        precio = self.normalize_price(adapter.get("precio"))
        url = self.clean_text(adapter.get("url"))
        categoria = self.clean_text(adapter.get("categoria")) or "Sin categoría"
        tienda = self.clean_text(adapter.get("tienda")) or self.default_store_name(spider)
        stock_texto = self.clean_text(adapter.get("stock"))
        imagen = self.clean_image(adapter.get("imagen"))
        marca = self.clean_text(adapter.get("marca"))
        descripcion = self.clean_text(adapter.get("descripcion"))

        if not nombre or not url:
            return item

        modelo_key = self.extract_model(nombre, marca)
        grupo_key = self.normalize_text(modelo_key)
        grupo_nombre = self.format_group_name(modelo_key)

        en_stock = self.parse_stock(stock_texto)

        categoria_id = self.get_or_create_categoria(categoria)
        tienda_id = self.get_or_create_tienda(tienda)

        self.cursor.execute("""
            SELECT idproductos, pro_precio, pro_en_stock
            FROM productos
            WHERE pro_url = %s
            LIMIT 1
        """, (url,))
        row = self.cursor.fetchone()

        if row:
            producto_id, precio_actual_db, stock_actual_db = row

            precio_anterior = precio_actual_db if precio_actual_db != precio else precio_actual_db

            self.cursor.execute("""
                UPDATE productos
                SET pro_nombre = %s,
                    pro_descripcion = %s,
                    pro_marca = %s,
                    pro_precio_anterior = %s,
                    pro_precio = %s,
                    pro_imagen = %s,
                    pro_en_stock = %s,
                    pro_fecha_scraping = NOW(),
                    pro_activo = 1,
                    tiendas_idtiendas = %s,
                    categorias_idcategorias = %s,
                    pro_grupo = %s,
                    pro_modelo = %s
                WHERE idproductos = %s
            """, (
                nombre,
                descripcion,
                marca,
                precio_anterior,
                precio if precio is not None else 0,
                imagen,
                en_stock,
                tienda_id,
                categoria_id,
                grupo_nombre,
                grupo_key,
                producto_id
            ))

            if (
                (precio is not None and precio_actual_db != precio)
                or stock_actual_db != en_stock
            ):
                self.insert_historial(producto_id, precio, en_stock)

        else:
            self.cursor.execute("""
                INSERT INTO productos (
                    pro_nombre,
                    pro_descripcion,
                    pro_marca,
                    pro_precio,
                    pro_precio_anterior,
                    pro_imagen,
                    pro_url,
                    pro_en_stock,
                    pro_fecha_scraping,
                    pro_activo,
                    tiendas_idtiendas,
                    categorias_idcategorias,
                    pro_grupo,
                    pro_modelo
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, NOW(), 1, %s, %s, %s, %s)
            """, (
                nombre,
                descripcion,
                marca,
                precio if precio is not None else 0,
                None,
                imagen,
                url,
                en_stock,
                tienda_id,
                categoria_id,
                grupo_nombre,
                grupo_key,
            ))

            producto_id = self.cursor.lastrowid
            self.insert_historial(producto_id, precio, en_stock)

        self.upsert_producto_precio(
            producto_id=producto_id,
            tienda_id=tienda_id,
            precio=precio,
            url=url,
            imagen=imagen,
            stock_texto=stock_texto
        )

        return item

    def insert_historial(self, producto_id, precio, en_stock):
        self.cursor.execute("""
            INSERT INTO historial_precios (
                productos_idproductos,
                his_precio,
                his_en_stock,
                his_fecha
            ) VALUES (%s, %s, %s, NOW())
        """, (
            producto_id,
            precio if precio is not None else 0,
            en_stock
        ))

    def get_or_create_categoria(self, nombre):
        self.cursor.execute("""
            SELECT idcategorias
            FROM categorias
            WHERE cat_nombre = %s
            LIMIT 1
        """, (nombre,))
        row = self.cursor.fetchone()

        if row:
            return row[0]

        self.cursor.execute("""
            INSERT INTO categorias (cat_nombre, cat_descripcion)
            VALUES (%s, NULL)
        """, (nombre,))
        return self.cursor.lastrowid

    def get_or_create_tienda(self, nombre):
        self.cursor.execute("""
            SELECT idtiendas
            FROM tiendas
            WHERE tie_nombre = %s
            LIMIT 1
        """, (nombre,))
        row = self.cursor.fetchone()

        if row:
            return row[0]

        self.cursor.execute("""
            INSERT INTO tiendas (
                tie_nombre,
                tie_descripcion,
                tie_logo,
                tie_ubicacion,
                tie_url
            ) VALUES (%s, NULL, NULL, NULL, NULL)
        """, (nombre,))
        return self.cursor.lastrowid

    def upsert_producto_precio(self, producto_id, tienda_id, precio, url, imagen, stock_texto):
        self.cursor.execute("""
            SELECT proprecio_id, precio
            FROM productos_precios
            WHERE productos_idproductos = %s
              AND tiendas_idtiendas = %s
              AND proprecio_url = %s
            LIMIT 1
        """, (producto_id, tienda_id, url))
        row = self.cursor.fetchone()

        if row:
            proprecio_id, precio_actual_db = row
            precio_anterior = precio_actual_db if precio_actual_db != precio else precio_actual_db

            self.cursor.execute("""
                UPDATE productos_precios
                SET precio = %s,
                    precio_anterior = %s,
                    proprecio_imagen = %s,
                    proprecio_stock = %s,
                    prop_estado = 'activo',
                    proprecio_fecha_actualizacion = NOW()
                WHERE proprecio_id = %s
            """, (
                precio if precio is not None else 0,
                precio_anterior,
                imagen,
                stock_texto or None,
                proprecio_id
            ))
        else:
            self.cursor.execute("""
                INSERT INTO productos_precios (
                    productos_idproductos,
                    tiendas_idtiendas,
                    precio,
                    precio_anterior,
                    proprecio_url,
                    proprecio_imagen,
                    proprecio_stock,
                    prop_estado,
                    proprecio_fecha_actualizacion
                ) VALUES (%s, %s, %s, NULL, %s, %s, %s, 'activo', NOW())
            """, (
                producto_id,
                tienda_id,
                precio if precio is not None else 0,
                url,
                imagen,
                stock_texto or None
            ))

    def parse_stock(self, texto):
        texto = (texto or "").strip().lower()

        if not texto:
            return 1

        negativos = [
            "agotado",
            "sin stock",
            "no disponible",
            "out of stock",
            "indisponible",
        ]

        for palabra in negativos:
            if palabra in texto:
                return 0

        return 1

    def clean_image(self, imagen):
        imagen = self.clean_text(imagen)
        if not imagen:
            return None
        if imagen.startswith("data:image"):
            return None
        return imagen

    def clean_text(self, value):
        if value is None:
            return ""
        return str(value).strip()

    def normalize_text(self, text):
        if not text:
            return ""

        text = text.lower()
        text = unicodedata.normalize("NFD", text)
        text = text.encode("ascii", "ignore").decode("utf-8")

        text = text.replace("+", " plus ")
        text = text.replace("-", " ")

        text = re.sub(r"[^a-z0-9\s]", " ", text)
        text = re.sub(r"\s+", " ", text).strip()

        return text

    def extract_model(self, nombre, marca=""):
        texto = self.normalize_text(nombre)

        basura = [
            "parlante", "speaker", "altavoz", "bluetooth", "wireless",
            "audifono", "auricular", "smartphone", "celular",
            "nuevo", "original", "negro", "blanco", "azul", "rojo",
            "verde", "gris", "plata", "rosa", "optico", "inalambrico"
        ]

        for palabra in basura:
            texto = re.sub(rf"\b{re.escape(palabra)}\b", " ", texto)

        texto = re.sub(r"\s+", " ", texto).strip()

        patrones = [
            r"\bjbl\s+flip\s+\d+\b",
            r"\bjbl\s+charge\s+\d+\b",
            r"\bjbl\s+go\s+\d+\b",
            r"\bgalaxy\s+a\d{1,3}\b",
            r"\bgalaxy\s+s\d{1,3}\b",
            r"\biphone\s+\d{1,2}\b",
            r"\bredmi\s+note\s+\d{1,2}\b",
            r"\bredmi\s+\d{1,2}\b",
            r"\bmoto\s+[a-z]+\d*\b",
            r"\bpoco\s+[a-z]+\d*\b",
        ]

        for patron in patrones:
            m = re.search(patron, texto)
            if m:
                return m.group(0).strip()

        palabras = texto.split()
        return " ".join(palabras[:4]).strip()

    def format_group_name(self, grupo):
        if not grupo:
            return ""

        grupo = self.normalize_text(grupo)
        return grupo.upper()

    def normalize_price(self, value):
        if value is None or value == "":
            return None

        if isinstance(value, (int, float)):
            return int(value)

        text = str(value).strip()
        text = (
            text.replace("₲", "")
            .replace("Gs.", "")
            .replace("Gs", "")
            .replace(".", "")
            .replace(",", "")
            .strip()
        )

        digits = "".join(ch for ch in text if ch.isdigit())
        if not digits:
            return None

        return int(digits)

    def default_store_name(self, spider):
        if spider and getattr(spider, "store_name", None):
            return str(spider.store_name).strip()

        if spider and getattr(spider, "name", None):
            return str(spider.name).replace("_", " ").strip().title()

        return "Tienda desconocida"