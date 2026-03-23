import re
import scrapy
from scraper.items import ProductoItem


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
        categoria_origen = response.css("h1::text").get(default="").strip()

        vistos = set()

        # En WooCommerce suelen existir enlaces a producto dentro del grid
        for href in response.css('a[href]::attr(href)').getall():
            href = response.urljoin(href.strip())

            if not href:
                continue
            if href in vistos:
                continue
            if "/categoria-producto/" in href:
                continue
            if "/tag/" in href:
                continue
            if "/page/" in href:
                continue
            if href.rstrip("/") == response.url.rstrip("/"):
                continue

            # Filtrar páginas internas que no son producto
            path = href.replace("https://www.fulloffice.com.py", "").replace("http://www.fulloffice.com.py", "")
            segmentos = [s for s in path.split("/") if s]

            # Regla simple: normalmente un producto queda como /slug-producto/
            # mientras categorías usan /categoria-producto/... y otras páginas tienen más niveles
            if len(segmentos) == 1:
                vistos.add(href)
                yield response.follow(
                    href,
                    callback=self.parse_producto,
                    meta={"categoria_origen": categoria_origen}
                )

        # Paginación WooCommerce típica
        next_page = (
            response.css('a.next::attr(href)').get()
            or response.css('a.page-numbers.next::attr(href)').get()
        )

        if not next_page:
            for a in response.css("a"):
                text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip()).lower()
                href = a.attrib.get("href", "").strip()
                if href and ("siguiente" in text or "next" in text):
                    next_page = href
                    break

        if next_page:
            yield response.follow(next_page, callback=self.parse)

    def parse_producto(self, response):
        item = ProductoItem()

        categoria_origen = response.meta.get("categoria_origen", "").strip()

        nombre = (
            response.css("h1.product_title::text").get()
            or response.css("h1.entry-title::text").get()
            or response.css("h1::text").get(default="")
        ).strip()

        # Precio WooCommerce
        precio_texto = " ".join(
            t.strip() for t in response.css(".price *::text, p.price *::text, span.woocommerce-Price-amount *::text").getall()
            if t.strip()
        )
        if not precio_texto:
            precio_texto = " ".join(response.css("body ::text").getall())

        precio = self.parse_precio(precio_texto)

        # Categoría
        categorias = response.css('.posted_in a::text, a[rel="tag"]::text').getall()
        categoria = categorias[0].strip() if categorias else (categoria_origen or "Sin categoría")

        # Marca tentativa
        marca = ""
        meta_text = " ".join(t.strip() for t in response.css(".product_meta *::text").getall() if t.strip())
        m = re.search(r"marca[:\s]+([A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ\-\+ ]+)", meta_text, re.I)
        if m:
            marca = m.group(1).strip()

        # Descripción
        descripcion = " ".join(
            t.strip() for t in response.css(
                "#tab-description *::text, .woocommerce-Tabs-panel--description *::text, .product .summary ~ div *::text"
            ).getall() if t.strip()
        )
        descripcion = descripcion[:1000]

        # Stock
        stock_text = " ".join(
            t.strip() for t in response.css(".stock *::text, .availability *::text, body ::text").getall()
            if t.strip()
        ).lower()

        if "sin stock" in stock_text or "agotado" in stock_text or "out of stock" in stock_text:
            stock = "Sin stock"
        else:
            stock = "En stock"

        # Imagen principal
        imagen = (
            response.css('.woocommerce-product-gallery__image a::attr(href)').get()
            or response.css('.woocommerce-product-gallery__image img::attr(src)').get()
            or response.css('.wp-post-image::attr(src)').get()
            or response.css('img[src*="uploads"]::attr(src)').get()
        )
        if imagen:
            imagen = response.urljoin(imagen)

        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen or ""
        item['marca'] = item['nombre'].split()[0]
        item["descripcion"] = descripcion

        if item["nombre"]:
            yield item

    def parse_precio(self, texto):
        if not texto:
            return None

        texto = texto.replace("\xa0", " ")

        patrones = [
            r'Gs\.?\s*([\d\.]+)',
            r'₲\s*([\d\.]+)',
            r'PYG\s*([\d\.]+)',
        ]

        for patron in patrones:
            match = re.search(patron, texto, re.I)
            if match:
                try:
                    return int(match.group(1).replace(".", ""))
                except ValueError:
                    return None

        return None