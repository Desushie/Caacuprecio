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
            cursorclass=pymysql.cursors.Cursor,
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

        modelo_base = self.extract_base_model(nombre, marca)
        grupo_nombre = self.format_group_name(modelo_base)
        modelo_detalle = self.extract_variant(nombre, marca, modelo_base)
        modelo_key = self.normalize_text(modelo_detalle)

        en_stock = self.parse_stock(stock_texto)

        categoria_id = self.get_or_create_categoria(categoria)
        tienda_id = self.get_or_create_tienda(tienda)

        self.cursor.execute(
            """
            SELECT idproductos, pro_precio, pro_en_stock
            FROM productos
            WHERE pro_url = %s
            LIMIT 1
            """,
            (url,),
        )
        row = self.cursor.fetchone()

        if row:
            producto_id, precio_actual_db, stock_actual_db = row
            precio_anterior = precio_actual_db

            self.cursor.execute(
                """
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
                """,
                (
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
                    modelo_key,
                    producto_id,
                ),
            )

            if (precio is not None and precio_actual_db != precio) or stock_actual_db != en_stock:
                self.insert_historial(producto_id, precio, en_stock)
        else:
            self.cursor.execute(
                """
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
                """,
                (
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
                    modelo_key,
                ),
            )

            producto_id = self.cursor.lastrowid
            self.insert_historial(producto_id, precio, en_stock)

        self.upsert_producto_precio(
            producto_id=producto_id,
            tienda_id=tienda_id,
            precio=precio,
            url=url,
            imagen=imagen,
            stock_texto=stock_texto,
        )

        return item

    def insert_historial(self, producto_id, precio, en_stock):
        self.cursor.execute(
            """
            INSERT INTO historial_precios (
                productos_idproductos,
                his_precio,
                his_en_stock,
                his_fecha
            ) VALUES (%s, %s, %s, NOW())
            """,
            (producto_id, precio if precio is not None else 0, en_stock),
        )

    def get_or_create_categoria(self, nombre):
        self.cursor.execute(
            """
            SELECT idcategorias
            FROM categorias
            WHERE cat_nombre = %s
            LIMIT 1
            """,
            (nombre,),
        )
        row = self.cursor.fetchone()
        if row:
            return row[0]

        self.cursor.execute(
            """
            INSERT INTO categorias (cat_nombre, cat_descripcion)
            VALUES (%s, NULL)
            """,
            (nombre,),
        )
        return self.cursor.lastrowid

    def get_or_create_tienda(self, nombre):
        self.cursor.execute(
            """
            SELECT idtiendas
            FROM tiendas
            WHERE tie_nombre = %s
            LIMIT 1
            """,
            (nombre,),
        )
        row = self.cursor.fetchone()
        if row:
            return row[0]

        self.cursor.execute(
            """
            INSERT INTO tiendas (
                tie_nombre,
                tie_descripcion,
                tie_logo,
                tie_ubicacion,
                tie_url
            ) VALUES (%s, NULL, NULL, NULL, NULL)
            """,
            (nombre,),
        )
        return self.cursor.lastrowid

    def upsert_producto_precio(self, producto_id, tienda_id, precio, url, imagen, stock_texto):
        self.cursor.execute(
            """
            SELECT proprecio_id, precio
            FROM productos_precios
            WHERE productos_idproductos = %s
              AND tiendas_idtiendas = %s
              AND proprecio_url = %s
            LIMIT 1
            """,
            (producto_id, tienda_id, url),
        )
        row = self.cursor.fetchone()

        if row:
            proprecio_id, precio_actual_db = row
            precio_anterior = precio_actual_db

            self.cursor.execute(
                """
                UPDATE productos_precios
                SET precio = %s,
                    precio_anterior = %s,
                    proprecio_imagen = %s,
                    proprecio_stock = %s,
                    prop_estado = 'activo',
                    proprecio_fecha_actualizacion = NOW()
                WHERE proprecio_id = %s
                """,
                (
                    precio if precio is not None else 0,
                    precio_anterior,
                    imagen,
                    stock_texto or None,
                    proprecio_id,
                ),
            )
        else:
            self.cursor.execute(
                """
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
                """,
                (
                    producto_id,
                    tienda_id,
                    precio if precio is not None else 0,
                    url,
                    imagen,
                    stock_texto or None,
                ),
            )

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
        text = text.replace("/", " ")
        text = text.replace('"', " pulg ")

        text = re.sub(r"[^a-z0-9\s]", " ", text)
        text = re.sub(r"\s+", " ", text).strip()
        return text

    def extract_base_model(self, nombre, marca=""):
        texto_original = self.normalize_text(f"{marca} {nombre}")
        texto = self.remove_noise_words(texto_original)

        brand = self.normalize_brand(marca) or self.detect_brand_from_text(texto)

        if brand == "JBL":
            jbl_group = self.extract_jbl_base_model(texto)
            if jbl_group:
                return jbl_group

        tv_group = self.extract_tv_group(texto, marca)
        if tv_group:
            return tv_group

        patrones = [
            (r"\bsamsung\s+(?:galaxy\s+)?a(\d{1,3})\b", lambda m: f"SAMSUNG GALAXY A{m.group(1)}"),
            (r"\bsamsung\s+(?:galaxy\s+)?s(\d{1,3})\b", lambda m: f"SAMSUNG GALAXY S{m.group(1)}"),
            (r"\biphone\s+(\d{1,2})\b", lambda m: f"IPHONE {m.group(1)}"),
            (r"\bxiaomi\s+redmi\s+note\s+(\d{1,2}[a-z]?)\b", lambda m: f"XIAOMI REDMI NOTE {m.group(1).upper()}"),
            (r"\bredmi\s+note\s+(\d{1,2}[a-z]?)\b", lambda m: f"XIAOMI REDMI NOTE {m.group(1).upper()}"),
            (r"\bxiaomi\s+redmi\s+(\d{1,2}[a-z]?)\b", lambda m: f"XIAOMI REDMI {m.group(1).upper()}"),
            (r"\bredmi\s+(\d{1,2}[a-z]?)\b", lambda m: f"XIAOMI REDMI {m.group(1).upper()}"),
            (r"\bpoco\s+([a-z]+\d*[a-z]*)\b", lambda m: f"POCO {m.group(1).upper()}"),
            (r"\bmoto\s+([a-z]+\d*[a-z]*)\b", lambda m: f"MOTO {m.group(1).upper()}"),
            (r"\bremington\b.*?\b([a-z]{1,4}\d{2,5}[a-z]*)\b", lambda m: f"REMINGTON {m.group(1).upper()}"),
        ]

        for patron, formatter in patrones:
            m = re.search(patron, texto)
            if m:
                return formatter(m)

        model_code = self.extract_generic_model_code(texto, brand)
        if model_code and brand:
            return f"{brand} {model_code}".strip()
        if model_code:
            return model_code

        marca_limpia = self.normalize_brand(marca)
        tokens = texto.split()
        if marca_limpia:
            tokens = [t for t in tokens if t != marca_limpia.lower()]

        base = " ".join(tokens[:4]).strip().upper()
        if marca_limpia:
            return f"{marca_limpia} {base}".strip()
        return base or nombre.upper().strip()

    def extract_tv_group(self, texto, marca=""):
        if "tv" not in texto and "televisor" not in texto:
            return None

        brand = self.normalize_brand(marca) or self.detect_brand_from_text(texto)
        size = self.extract_tv_size(texto)
        resolution = self.extract_tv_resolution(texto)
        platform = self.extract_tv_platform(texto)
        series = self.extract_tv_series(texto, brand)

        if series and brand:
            return f"{brand} TV {series}".strip()

        parts = [brand or "TV", "TV"]
        if platform:
            parts.append(platform)
        if size:
            parts.append(size)
        if resolution:
            parts.append(resolution)

        return " ".join(p for p in parts if p).strip()

    def extract_tv_size(self, texto):
        patrones = [
            r"\b(24|28|32|40|42|43|50|55|58|60|65|70|75|85)\s*(?:pulg|pulgadas|inch|in)?\b",
            r"\b(?:un|lh|ua|qn|cu|du|ls)(24|28|32|40|42|43|50|55|58|60|65|70|75|85)[a-z0-9]*\b",
        ]
        for patron in patrones:
            m = re.search(patron, texto)
            if m:
                return m.group(1)
        return None

    def extract_tv_resolution(self, texto):
        if re.search(r"\b4k\b|\buhd\b", texto):
            return "4K"
        if re.search(r"\bfhd\b|\bfull\s+hd\b", texto):
            return "FHD"
        if re.search(r"\bhd\b", texto):
            return "HD"
        return None

    def extract_tv_platform(self, texto):
        if re.search(r"\bgoogle\s+tv\b|\bsmart\s+google\b|\bgoogle\b", texto):
            return "SMART GOOGLE"
        if re.search(r"\bandroid\b", texto):
            return "SMART ANDROID"
        if re.search(r"\bsmart\b", texto):
            return "SMART"
        return None

    def extract_tv_series(self, texto, brand=""):
        patrones = [
            r"\b((?:un|lh|ua|qn|cu|du|ls)\d{2}[a-z]\d{4}[a-z0-9]{0,8})\b",
            r"\b([a-z]{1,4}\d{4,8}[a-z0-9]{0,6})\b",
        ]
        for patron in patrones:
            m = re.search(patron, texto)
            if m:
                serie = m.group(1).upper()
                if not re.fullmatch(r"\d+", serie):
                    return serie
        return None

    def detect_brand_from_text(self, texto):
        brands = [
            "SAMSUNG", "TOKYO", "LG", "JVC", "TCL", "PHILIPS", "AOC", "JAMES",
            "VISION", "XIAOMI", "REDMI", "POCO", "REMINGTON", "JBL", "PIONEER",
            "MIDAS", "DAEWOO", "CONSUMER", "QUANTA", "APPLE", "IPHONE",
        ]
        for brand in brands:
            if re.search(rf"\b{brand.lower()}\b", texto):
                if brand == "REDMI":
                    return "XIAOMI"
                if brand == "IPHONE":
                    return "APPLE"
                return brand
        return ""

    def extract_variant(self, nombre, marca="", base_model=""):
        texto = self.normalize_text(f"{marca} {nombre}")
        texto = self.remove_noise_words(texto)
        brand = self.normalize_brand(marca) or self.detect_brand_from_text(texto)

        if brand == "JBL":
            return self.extract_jbl_variant(texto, base_model)

        if "tv" in texto or "televisor" in texto:
            return self.extract_tv_variant(texto, base_model)

        specs = []

        if re.search(r"\b5g\b", texto):
            specs.append("5G")
        if re.search(r"\b4g\b", texto):
            specs.append("4G")

        ram_match = re.search(r"\b(\d{1,2})\s*gb\s*[+/x]\s*(\d{2,4})\s*gb\b", texto)
        if ram_match:
            specs.append(f"{ram_match.group(1)}GB/{ram_match.group(2)}GB")
        else:
            ram_storage_match = re.search(r"\b(\d{1,2})\+(\d{2,4})\s*gb\b", texto)
            if ram_storage_match:
                specs.append(f"{ram_storage_match.group(1)}GB/{ram_storage_match.group(2)}GB")
            else:
                storage_match = re.findall(r"\b(\d{2,4})\s*gb\b", texto)
                if storage_match:
                    numeros = [int(x) for x in storage_match]
                    grandes = [n for n in numeros if n >= 32]
                    chicos = [n for n in numeros if 2 <= n <= 24]
                    if chicos and grandes:
                        specs.append(f"{chicos[0]}GB/{grandes[0]}GB")
                    elif grandes:
                        specs.append(f"{grandes[0]}GB")
                    elif chicos:
                        specs.append(f"{chicos[0]}GB")

        code = self.extract_generic_model_code(texto, brand)
        if code and code not in (base_model or ""):
            specs.append(code)

        base_model = (base_model or "").upper().strip()
        if specs:
            detalle = f"{base_model} {' '.join(dict.fromkeys(specs))}".strip()
        else:
            detalle = base_model or nombre.upper().strip()

        return detalle

    def extract_tv_variant(self, texto, base_model=""):
        extras = []
        if re.search(r"\bchromecast\b", texto):
            extras.append("CHROMECAST")
        if re.search(r"\bframeless\b", texto):
            extras.append("FRAMELESS")

        serie = self.extract_tv_series(texto)
        if serie and serie not in (base_model or ""):
            extras.append(serie)

        base_model = (base_model or "").upper().strip()
        if extras:
            return f"{base_model} {' '.join(dict.fromkeys(extras))}".strip()
        return base_model or texto.upper().strip()

    def extract_jbl_base_model(self, texto):
        patrones = [
            (r"\bjbl\s+flip\s+(\d+)\b", lambda m: f"JBL FLIP {m.group(1)}"),
            (r"\bjbl\s+charge\s+(\d+)\b", lambda m: f"JBL CHARGE {m.group(1)}"),
            (r"\bjbl\s+go\s+(\d+)\b", lambda m: f"JBL GO {m.group(1)}"),
            (r"\bjbl\s+clip\s+(\d+)\b", lambda m: f"JBL CLIP {m.group(1)}"),
            (r"\bjbl\s+partybox\s+(\d{2,4})\b", lambda m: f"JBL PARTYBOX {m.group(1)}"),
            (r"\bjbl\s+boombox\s+(\d+)\b", lambda m: f"JBL BOOMBOX {m.group(1)}"),
            (r"\bjbl\s+tune\s+([a-z0-9]+)\b", lambda m: f"JBL TUNE {m.group(1).upper()}"),
            (r"\bjbl\s+wave\s+([a-z0-9]+)\b", lambda m: f"JBL WAVE {m.group(1).upper()}"),
            (r"\bjbl\s+live\s+([a-z0-9]+)\b", lambda m: f"JBL LIVE {m.group(1).upper()}"),
            (r"\bjbl\s+endurance\s+([a-z0-9]+)\b", lambda m: f"JBL ENDURANCE {m.group(1).upper()}"),
        ]
        for patron, formatter in patrones:
            m = re.search(patron, texto)
            if m:
                return formatter(m)

        code = self.extract_generic_model_code(texto, "JBL")
        if code:
            return f"JBL {code}"
        return None

    def extract_jbl_variant(self, texto, base_model=""):
        extras = []

        for token in ["wifi", "bt", "bluetooth", "wireless", "portable", "mini"]:
            if re.search(rf"\b{re.escape(token)}\b", texto):
                extras.append(token.upper())

        color = self.extract_color(texto)
        if color:
            extras.append(color)

        base_model = (base_model or "").upper().strip()
        if extras:
            return f"{base_model} {' '.join(dict.fromkeys(extras))}".strip()
        return base_model or texto.upper().strip()

    def extract_generic_model_code(self, texto, brand=""):
        tokens = re.findall(
            r"\b[a-z]{1,4}\d{2,6}[a-z]{0,4}\b|\b[a-z]{2,6}\d{2,6}\b|\b\d{2,6}[a-z]{2,6}\b",
            texto,
        )
        stop = {
            "5g", "4g", "8gb", "12gb", "16gb", "32gb", "43fhd", "32hd", "220v",
            "110v", "127v", "1875w", "2000w", "256gb", "128gb", "512gb", "1080p",
            "2160p",
        }
        for token in tokens:
            t = token.lower()
            if t in stop:
                continue
            if brand and t == brand.lower():
                continue
            if re.fullmatch(r"\d{2,4}", t):
                continue
            if re.fullmatch(r"\d{1,2}gb", t):
                continue
            return token.upper()
        return None

    def extract_color(self, texto):
        colores = {
            "negro": "NEGRO",
            "black": "BLACK",
            "blanco": "BLANCO",
            "white": "WHITE",
            "azul": "AZUL",
            "blue": "BLUE",
            "rojo": "ROJO",
            "red": "RED",
            "gris": "GRIS",
            "gray": "GRAY",
            "grey": "GREY",
        }
        for palabra, valor in colores.items():
            if re.search(rf"\b{re.escape(palabra)}\b", texto):
                return valor
        return None

    def remove_noise_words(self, texto):
        basura = [
            "marca", "celular", "smartphone", "telefono", "movil", "liberado",
            "dual", "sim", "nuevo", "original", "oficial", "version", "modelo",
            "color", "negro", "blanco", "azul", "rojo", "verde", "gris", "plata",
            "dorado", "ngo", "en", "stock", "disponible", "oferta", "ofertas",
            "secador", "de", "pelo", "mod", "con", "para", "y", "incluido",
            "incluida", "incluye", "c",
        ]

        for palabra in basura:
            texto = re.sub(rf"\b{re.escape(palabra)}\b", " ", texto)

        texto = re.sub(r"\s+", " ", texto).strip()
        return texto

    def normalize_brand(self, marca):
        marca = self.normalize_text(marca)
        return marca.upper().strip()

    def format_group_name(self, grupo):
        if not grupo:
            return ""
        grupo = re.sub(r"\s+", " ", grupo).strip()
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
