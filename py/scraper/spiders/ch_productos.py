import json
import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class CHProductosSpider(scrapy.Spider):
    name = "ch_productos"
    store_name = "Comfort House"
    allowed_domains = ["ch.com.py"]
    start_urls = [
        "https://www.ch.com.py/climatizacion",
        "https://www.ch.com.py/tecnologia",
        "https://www.ch.com.py/electrodomesticos",
        "https://www.ch.com.py/muebles-y-accesorios",
        "https://www.ch.com.py/salud-y-belleza",
        "https://www.ch.com.py/deporte-y-aire-libre",
        "https://www.ch.com.py/herramientas-maquinas-y-equipos",
        "https://www.ch.com.py/bebes-y-juguetes",
        "https://www.ch.com.py/motos-y-accesorios",
    ]

    CATEGORIAS_GENERICAS = {
        "",
        "producto",
        "productos",
        "catalogo",
        "catálogo",
        "item",
        "items",
        "sin categoría",
        "sin categoria",
    }

    def parse(self, response):
        categoria_origen = response.css("h1::text").get(default="").strip()

        vistos = set()
        for href in response.css('a[href*="/catalogo/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            href = href.split("?")[0].rstrip("/")

            if not href or href in vistos:
                continue

            vistos.add(href)
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen}
            )

        next_page = None
        for a in response.css("a"):
            text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip())
            href = a.attrib.get("href", "").strip()
            if "Página Siguiente" in text and href:
                next_page = href
                break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()

        categoria_origen = response.meta.get("categoria_origen", "").strip()
        data = self.extract_product_json(response)

        if data:
            producto = data.get("producto", {}) or {}
            variante = data.get("variante", {}) or {}

            nombre = (
                data.get("nombreCompleto")
                or producto.get("nombre")
                or response.css("h1::text").get(default="").strip()
            )
            nombre = self.limpiar_texto(nombre)

            categoria_sitio = self.limpiar_categoria(producto.get("categoria"))
            categoria_origen_limpia = self.limpiar_categoria(categoria_origen)

            categoria = (
                categoria_origen_limpia
                or categoria_sitio
                or extract_category(nombre)
                or "Otros"
            )

            marca_sitio = self.limpiar_texto(producto.get("marca") or "")
            marca = marca_sitio if marca_sitio else extract_brand(nombre)

            precio = data.get("precioMonto")
            stock = "En stock" if variante.get("tieneStock") else "Consultar stock"
            descripcion = self.extraer_descripcion(response, data, nombre)

            imagen = None
            img_obj = variante.get("img", {})
            if isinstance(img_obj, dict):
                imagen = img_obj.get("u")

            if imagen and imagen.startswith("//"):
                imagen = "https:" + imagen
            elif imagen:
                imagen = response.urljoin(imagen)

            url = (variante.get("url") or response.url).split("?")[0].rstrip("/")

            item["nombre"] = nombre
            item["precio"] = self.to_int(precio)
            item["url"] = url
            item["categoria"] = categoria
            item["tienda"] = self.store_name
            item["stock"] = stock
            item["imagen"] = imagen or ""
            item["marca"] = marca
            item["descripcion"] = descripcion

            item = self.normalizar_item(item)

            if item["nombre"]:
                yield item
            return

        nombre = self.limpiar_texto(response.css("h1::text").get(default="").strip())
        precio_texto = " ".join(response.css("body ::text").getall())
        precio = self.parse_precio(precio_texto)

        categoria = (
            self.limpiar_categoria(categoria_origen)
            or extract_category(nombre)
            or "Otros"
        )

        stock_texto = " ".join(response.css("body ::text").getall()).lower()
        stock = "Consultar stock" if "sin stock" in stock_texto or "agotado" in stock_texto else "En stock"

        imagen = response.css('img[src*="/catalogo/"]::attr(src)').get()
        if imagen:
            imagen = response.urljoin(imagen)

        descripcion = self.extraer_descripcion(response, None, nombre)

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen or ""
        item["marca"] = extract_brand(nombre)
        item["descripcion"] = descripcion

        item = self.normalizar_item(item)

        if item["nombre"]:
            yield item

    def normalizar_item(self, item):
        marca = self.limpiar_texto(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = extract_brand(item.get("nombre") or "") or "Genérico"
        else:
            item["marca"] = marca

        categoria = self.limpiar_categoria(item.get("categoria") or "")
        if not categoria:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Otros"
        else:
            item["categoria"] = categoria

        item["descripcion"] = self.limpiar_descripcion(item.get("descripcion") or "", item.get("nombre") or "")
        return item

    def limpiar_categoria(self, categoria):
        categoria = self.limpiar_texto(categoria or "")
        if not categoria:
            return ""

        cat_lower = categoria.lower()
        if cat_lower in self.CATEGORIAS_GENERICAS:
            return ""

        mapeo = {
            "climatizacion": "Climatización",
            "tecnologia": "Tecnología",
            "electrodomesticos": "Electrodomésticos",
            "muebles y accesorios": "Muebles y Accesorios",
            "salud y belleza": "Salud y Belleza",
            "deporte y aire libre": "Deporte y Aire Libre",
            "herramientas maquinas y equipos": "Herramientas",
            "bebes y juguetes": "Bebés y Juguetes",
            "motos y accesorios": "Motos y Accesorios",
        }

        normalizada = self.normalizar_texto_simple(categoria)
        return mapeo.get(normalizada, categoria)

    def extraer_descripcion(self, response, data=None, nombre=""):
        candidatos = []

        if data:
            carac = data.get("carac")
            if isinstance(carac, list) and carac:
                partes = []
                for c in carac:
                    if isinstance(c, str) and c.strip():
                        partes.append(c.strip())
                    elif isinstance(c, dict):
                        for v in c.values():
                            if isinstance(v, str) and v.strip():
                                partes.append(v.strip())
                if partes:
                    candidatos.append(" | ".join(partes))

            for key in ["descripcion", "description", "desc"]:
                value = data.get(key)
                if isinstance(value, str) and value.strip():
                    candidatos.append(value.strip())

        descripcion_html = " ".join(
            t.strip()
            for t in response.css(
                ".product-description *::text, "
                ".description *::text, "
                ".content-description *::text, "
                ".product-detail-description *::text"
            ).getall()
            if t.strip()
        )
        if descripcion_html:
            candidatos.append(descripcion_html)

        textos = response.css("body ::text").getall()
        textos = [t.strip() for t in textos if t.strip()]

        inicio = None
        fin = None

        for i, t in enumerate(textos):
            t_lower = t.lower()
            if t_lower in {"características", "caracteristicas"}:
                inicio = i + 1
                continue

            if inicio is not None and (
                "productos que te pueden interesar" in t_lower
                or "productos relacionados" in t_lower
                or "consultá por este producto" in t_lower
                or "consulta por este producto" in t_lower
            ):
                fin = i
                break

        if inicio is not None:
            bloque = textos[inicio:fin] if fin is not None else textos[inicio:inicio + 25]
            bloque = [t for t in bloque if len(t) > 1]
            if bloque:
                candidatos.append(" ".join(bloque))

        for texto in candidatos:
            limpio = self.limpiar_descripcion(texto, nombre)
            if limpio and len(limpio) >= 20:
                return limpio[:1000]

        return ""

    def limpiar_descripcion(self, texto, nombre=""):
        if not texto:
            return ""

        texto = re.sub(r"\s+", " ", texto).strip()

        # quitar solo "Descripción" al inicio
        texto = re.sub(r"^\s*descripción\s*[:\-]?\s*", "", texto, flags=re.I)

        # quitar casos tipo "Capacidad 9 a 10 Kg Descripción ..."
        texto = re.sub(
            r"^\s*[A-ZÁÉÍÓÚÑa-záéíóúñ0-9\.,/%°()\- ]{0,80}?\bdescripción\b\s*[:\-]?\s*",
            "",
            texto,
            flags=re.I
        )

        # quitar nombre repetido al inicio solo si aparece exacto
        if nombre:
            nombre_esc = re.escape(nombre.strip())
            texto = re.sub(rf"^\s*{nombre_esc}\s*", "", texto, flags=re.I)

        # quitar aviso muy común del final
        texto = re.sub(
            r"\(las imágenes son ilustrativas.*?\)",
            "",
            texto,
            flags=re.I
        )
        texto = re.sub(
            r"\(las imagenes son ilustrativas.*?\)",
            "",
            texto,
            flags=re.I
        )

        texto = re.sub(r"\s{2,}", " ", texto).strip(" .-|")

        return texto[:1000]

    def extract_product_json(self, response):
        scripts = response.css("script::text").getall()
        body_text = "\n".join(scripts + response.css("body ::text").getall())

        match = re.search(
            r'(\{"sku":\{.*?"precioMonto":\d+.*?\})',
            body_text,
            re.DOTALL
        )
        if not match:
            return None

        raw = match.group(1)

        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return None

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'PYG\s*([\d\.]+)', texto)
        if not match:
            return None

        try:
            return int(match.group(1).replace(".", ""))
        except ValueError:
            return None

    def to_int(self, value):
        if value is None or value == "":
            return None
        try:
            return int(value)
        except (TypeError, ValueError):
            return None

    def limpiar_texto(self, texto):
        if texto is None:
            return ""
        texto = re.sub(r"\s+", " ", str(texto)).strip()
        return texto

    def normalizar_texto_simple(self, texto):
        texto = self.limpiar_texto(texto).lower()
        reemplazos = str.maketrans({
            "á": "a",
            "é": "e",
            "í": "i",
            "ó": "o",
            "ú": "u",
            "ü": "u",
            "ñ": "n",
        })
        texto = texto.translate(reemplazos)
        return texto