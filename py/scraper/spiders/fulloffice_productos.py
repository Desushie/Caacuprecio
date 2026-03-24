import re
import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class FullOfficeProductosSpider(scrapy.Spider):
    name = "fulloffice_productos"
    store_name = "Full Office"
    allowed_domains = ["fulloffice.com.py"]

    start_urls = [
        "https://www.fulloffice.com.py/categoria-producto/outlet/",
        "https://www.fulloffice.com.py/categoria-producto/promociones/",
        "https://www.fulloffice.com.py/categoria-producto/tensiometro/",
        "https://www.fulloffice.com.py/categoria-producto/articulos-de-cocina/",
        "https://www.fulloffice.com.py/categoria-producto/electronicos/",
        "https://www.fulloffice.com.py/categoria-producto/ferreteria/",
        "https://www.fulloffice.com.py/categoria-producto/informatica-y-tecnologia/",
        "https://www.fulloffice.com.py/categoria-producto/papeleria/",
        "https://www.fulloffice.com.py/categoria-producto/otros-productos/",
        "https://www.fulloffice.com.py/categoria-producto/hogar/",
        "https://www.fulloffice.com.py/categoria-producto/electrodomesticos/",
    ]

    def parse(self, response):
        categoria_origen = (
            response.css("h1::text").get()
            or response.css("title::text").get(default="")
        ).strip()

        vistos = set()

        for href in response.css('a[href]::attr(href)').getall():
            href = response.urljoin(href.strip())

            if not href:
                continue

            # 👉 SOLO productos
            if "/producto/" not in href:
                continue

            href = href.split("?")[0].rstrip("/")

            if href in vistos:
                continue

            vistos.add(href)

            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # 👉 paginación
        next_page = response.css('a.next::attr(href)').get()

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        nombre = (
            response.css("h1::text").get()
            or response.css(".product_title::text").get()
            or response.css("title::text").get(default="")
        ).strip()

        if not nombre:
            return

        nombre_lower = nombre.lower()

        #  filtrar páginas basura
        if nombre_lower in ["tienda", "nosotros"]:
            return

        if any(x in response.url for x in ["/nosotros", "/tienda"]):
            return

        #  PRECIO
        body_text = " ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        )

        precio = self.parse_precio(body_text)

        # ❌ ignorar precios inválidos
        if precio is None or precio <= 0:
            return

        #  IMAGEN
        imagen = self.extraer_imagen(response)

        #  evitar placeholder
        if imagen and "placeholder" in imagen.lower():
            imagen = ""

        #  MARCA Y CATEGORIA
        marca = extract_brand(nombre)
        categoria = extract_category(nombre)

        #  DESCRIPCIÓN
        descripcion = self.extraer_descripcion(response)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = "En stock"
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion

        item = self.normalizar_item(item)

        yield item

    # =========================
    # UTILIDADES
    # =========================

    def normalizar_item(self, item):
        marca = (item.get("marca") or "").strip()
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = (item.get("categoria") or "").strip()
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized"}:
            item["categoria"] = "Otros"
        else:
            item["categoria"] = categoria

        return item


    def extraer_imagen(self, response):
        imagen = response.css('meta[property="og:image"]::attr(content)').get()
        if imagen and not imagen.startswith("data:image"):
            return response.urljoin(imagen)

        for src in response.css('img::attr(src)').getall():
            src = (src or "").strip()

            if not src or src.startswith("data:image"):
                continue

            if any(ext in src.lower() for ext in [".webp", ".jpg", ".jpeg", ".png"]):
                return response.urljoin(src)

        return ""

    def extraer_descripcion(self, response):
        descripcion = " ".join(
            t.strip()
            for t in response.css(
                ".woocommerce-Tabs-panel--description *::text, "
                "#tab-description *::text, "
                ".product .woocommerce-product-details__short-description *::text"
            ).getall()
            if t.strip()
        )

        if descripcion:
            return descripcion[:1000]

        # fallback
        textos = response.css("body ::text").getall()
        textos = [t.strip() for t in textos if t.strip()]

        inicio = None
        fin = None

        for i, t in enumerate(textos):
            t_lower = t.lower()

            if t_lower == "descripción" or t_lower == "descripcion":
                inicio = i + 1
                continue

            if inicio is not None and (
                "productos relacionados" in t_lower
                or "también te puede gustar" in t_lower
                or "tambien te puede gustar" in t_lower
            ):
                fin = i
                break

        if inicio is not None:
            bloque = textos[inicio:fin] if fin else textos[inicio:inicio + 25]
            bloque = [t for t in bloque if len(t) > 1]
            return " ".join(bloque)[:1000]

        return ""

    def parse_precio(self, texto):
        if not texto:
            return None

        match = re.search(r'₲\s*([\d\.]+)', texto)

        if not match:
            return None

        try:
            return int(match.group(1).replace(".", ""))
        except ValueError:
            return None