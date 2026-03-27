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

    def parse(self, response):
        categoria_origen = response.css("h1::text").get(default="").strip()

        vistos = set()
        for href in response.css('a[href*="/catalogo/"]::attr(href)').getall():
            href = response.urljoin(href.strip())
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

            categoria_raw = producto.get("categoria") or categoria_origen or ""
            categoria = extract_category(categoria_raw) or extract_category(nombre) or categoria_raw or "Sin categoría"
            marca_sitio = (producto.get("marca") or "").strip()
            marca = marca_sitio if marca_sitio else extract_brand(nombre)
            precio = data.get("precioMonto")
            stock = "En stock" if variante.get("tieneStock") else "Sin stock"
            descripcion = self.extraer_descripcion(response, data)

            imagen = None
            img_obj = variante.get("img", {})
            if isinstance(img_obj, dict):
                imagen = img_obj.get("u")

            if imagen and imagen.startswith("//"):
                imagen = "https:" + imagen
            elif imagen:
                imagen = response.urljoin(imagen)

            url = (variante.get("url") or response.url).split("?")[0].rstrip("/")

            item["nombre"] = (nombre or "").strip()
            item["precio"] = self.to_int(precio)
            item["url"] = url
            item["categoria"] = categoria.strip()
            item["tienda"] = self.store_name
            item["stock"] = stock
            item["imagen"] = imagen or ""
            item["marca"] = marca
            item["descripcion"] = descripcion

            item = self.normalizar_item(item)

            if item["nombre"]:
                yield item
            return

        nombre = response.css("h1::text").get(default="").strip()
        precio_texto = " ".join(response.css("body ::text").getall())
        precio = self.parse_precio(precio_texto)

        categoria = extract_category(categoria_origen) or extract_category(nombre) or categoria_origen or "Sin categoría"
        stock_texto = " ".join(response.css("body ::text").getall()).lower()
        stock = "Sin stock" if "sin stock" in stock_texto or "agotado" in stock_texto else "En stock"

        imagen = response.css('img[src*="/catalogo/"]::attr(src)').get()
        if imagen:
            imagen = response.urljoin(imagen)

        descripcion = self.extraer_descripcion(response, None)

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
        marca = (item.get("marca") or "").strip()
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = (item.get("categoria") or "").strip()
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized"}:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Otros"
        else:
            item["categoria"] = categoria

        return item

    def extraer_descripcion(self, response, data=None):
        # 1. JSON
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
                    return " | ".join(partes)[:1000]

            for key in ["descripcion", "description", "desc"]:
                value = data.get(key)
                if isinstance(value, str) and value.strip():
                    return value.strip()[:1000]

        # 2. Selectores HTML más específicos
        descripcion = " ".join(
            t.strip()
            for t in response.css(
                ".product-description *::text, "
                ".description *::text, "
                ".content-description *::text, "
                ".product-detail-description *::text"
            ).getall()
            if t.strip()
        )
        if descripcion:
            return descripcion[:1000]

        # 3. Fallback desde "Características"
        textos = response.css("body ::text").getall()
        textos = [t.strip() for t in textos if t.strip()]

        inicio = None
        fin = None

        for i, t in enumerate(textos):
            t_lower = t.lower()
            if t_lower == "características" or t_lower == "caracteristicas":
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
            return " ".join(bloque)[:1000]

        return ""

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