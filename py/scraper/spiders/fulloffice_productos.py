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

        # links de producto más precisos
        for href in response.css('a[href*="/producto/"]::attr(href)').getall():
            href = response.urljoin((href or "").strip())
            if not href:
                continue

            href = href.split("?")[0]
            if href in vistos:
                continue
            vistos.add(href)

            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # paginación más flexible para WooCommerce
        next_page = (
            response.css('a.next.page-numbers::attr(href)').get()
            or response.css('a.page-numbers.next::attr(href)').get()
            or response.xpath('//a[contains(@class,"next")]/@href').get()
        )

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        nombre = (
            response.css("h1.product_title::text").get()
            or response.css("h1::text").get()
            or response.css("title::text").get(default="")
        ).strip()

        if not nombre:
            return

        nombre_lower = nombre.lower()
        if nombre_lower in {"tienda", "nosotros"}:
            return
        if any(x in response.url for x in ["/nosotros", "/tienda"]):
            return

        precio = self.extraer_precio(response)
        if precio is None or precio <= 0:
            return

        imagen = self.extraer_imagen(response)
        if imagen and "placeholder" in imagen.lower():
            imagen = ""

        categoria_origen = response.meta.get("categoria_origen", "").strip()
        marca = extract_brand(nombre)
        categoria = (
            extract_category(categoria_origen)
            or extract_category(nombre)
            or categoria_origen
            or "Otros"
        )

        descripcion = self.extraer_descripcion(response)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0]
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = "En stock"
        item["imagen"] = imagen
        item["marca"] = marca or "Genérico"
        item["descripcion"] = descripcion

        yield self.normalizar_item(item)

    def normalizar_item(self, item):
        marca = (item.get("marca") or "").strip()
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"

        categoria = (item.get("categoria") or "").strip()
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized"}:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Otros"

        return item

    def extraer_precio(self, response):
        candidatos = [
            response.css('p.price ins .woocommerce-Price-amount bdi::text').get(),
            response.css('p.price .woocommerce-Price-amount bdi::text').get(),
            response.css('.summary .price .amount::text').get(),
            response.css('meta[property="product:price:amount"]::attr(content)').get(),
            response.css('span.woocommerce-Price-amount.amount bdi::text').get(),
        ]

        for c in candidatos:
            valor = self.parse_precio(c)
            if valor:
                return valor

        body_text = " ".join(
            t.strip() for t in response.css("body ::text").getall() if t.strip()
        )
        return self.parse_precio(body_text)

    def parse_precio(self, texto):
        if not texto:
            return None

        texto = " ".join(str(texto).split())

        patrones = [
            r'₲\s*([\d\.\,]+)',
            r'Gs\.?\s*([\d\.\,]+)',
            r'PYG\s*([\d\.\,]+)',
            r'guaran[ií]es?\s*([\d\.\,]+)',
        ]

        for patron in patrones:
            m = re.search(patron, texto, re.I)
            if m:
                numero = re.sub(r"[^\d]", "", m.group(1))
                if numero.isdigit():
                    return int(numero)

        # fallback: agarrar números grandes tipo 1299000
        m = re.search(r'\b(\d{3,9})\b', texto.replace(".", "").replace(",", ""))
        if m:
            try:
                return int(m.group(1))
            except ValueError:
                pass

        return None

    def extraer_imagen(self, response):
        imagen = response.css('meta[property="og:image"]::attr(content)').get()
        if imagen and not imagen.startswith("data:image"):
            return response.urljoin(imagen)

        for src in response.css('img::attr(src), img::attr(data-src)').getall():
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
                "#tab-description *::text, "
                ".woocommerce-Tabs-panel--description *::text, "
                ".woocommerce-product-details__short-description *::text, "
                ".summary .woocommerce-product-details__short-description *::text"
            ).getall()
            if t.strip()
        )

        descripcion = re.sub(r'\s+', ' ', descripcion).strip()
        return descripcion[:1000] if descripcion else ""