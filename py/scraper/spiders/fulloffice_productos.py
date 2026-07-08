import re
import scrapy

from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class FullOfficeProductosSpider(scrapy.Spider):
    name = "fulloffice_productos"
    store_name = "Full Office"
    allowed_domains = ["fulloffice.com.py", "www.fulloffice.com.py"]

    custom_settings = {
        "DOWNLOAD_DELAY": 1,
        "RANDOMIZE_DOWNLOAD_DELAY": True,
        "CONCURRENT_REQUESTS": 2,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 2,
        "DUPEFILTER_DEBUG": True,
        "DEFAULT_REQUEST_HEADERS": {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
        },
    }

    # /tienda/ muestra las categorías. Además se intentan sitemaps comunes de WordPress/WooCommerce.
    start_urls = [
        "https://www.fulloffice.com.py/tienda/",
        "https://www.fulloffice.com.py/product-sitemap.xml",
        "https://www.fulloffice.com.py/product-sitemap1.xml",
        "https://www.fulloffice.com.py/sitemap_index.xml",
        "https://www.fulloffice.com.py/categoria-producto/outlet/",
        "https://www.fulloffice.com.py/categoria-producto/promociones/",
        "https://www.fulloffice.com.py/categoria-producto/electronicos/",
        "https://www.fulloffice.com.py/categoria-producto/informatica-y-tecnologia/",
        "https://www.fulloffice.com.py/categoria-producto/hogar/",
        "https://www.fulloffice.com.py/categoria-producto/electrodomesticos/",
    ]

    def parse(self, response):
        url_limpia = response.url.split("?")[0].rstrip("/")

        # Si un sitemap o una categoría nos manda directo a un producto.
        if "/producto/" in url_limpia:
            yield from self.parse_producto(response)
            return

        # Sitemaps XML: sacar URLs de productos/categorías y otros sitemaps relacionados.
        content_type = response.headers.get(b"Content-Type", b"").decode("latin1").lower()
        if "xml" in content_type or response.url.endswith(".xml"):
            yield from self.parse_sitemap(response)
            return

        categoria_origen = self.extract_page_category(response)

        producto_links = self.extract_unique_links(response, [
            'li.product a.woocommerce-LoopProduct-link::attr(href)',
            '.products a[href*="/producto/"]::attr(href)',
            '.product a[href*="/producto/"]::attr(href)',
            'a[href*="/producto/"]::attr(href)',
        ])

        self.logger.warning(f"[{response.url}] productos encontrados: {len(producto_links)}")

        for href in producto_links:
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={"categoria_origen": categoria_origen},
            )

        # Full Office tiene muchas categorías en el lateral de /tienda/ y categorías.
        categoria_links = self.extract_unique_links(response, [
            '.product-categories a[href*="/categoria-producto/"]::attr(href)',
            '.cat-item a[href*="/categoria-producto/"]::attr(href)',
            'a[href*="/categoria-producto/"]::attr(href)',
        ])

        self.logger.warning(f"[{response.url}] categorías encontradas: {len(categoria_links)}")

        for href in categoria_links:
            yield response.follow(href, callback=self.parse)

        next_page = (
            response.css('a.next.page-numbers::attr(href)').get()
            or response.css('a.page-numbers.next::attr(href)').get()
            or response.xpath('//a[contains(@class,"next")]/@href').get()
            or response.css('link[rel="next"]::attr(href)').get()
        )

        if next_page:
            self.logger.warning(f"[{response.url}] -> siguiente página: {next_page}")
            yield response.follow(next_page, callback=self.parse)

    def parse_sitemap(self, response):
        urls = response.xpath('//*[local-name()="loc"]/text()').getall()
        self.logger.warning(f"[{response.url}] URLs en sitemap: {len(urls)}")

        for raw in urls:
            href = self.clean_url(raw)
            if not href:
                continue

            low = href.lower()
            if "/producto/" in low:
                yield response.follow(href, callback=self.parse_producto)
            elif "/categoria-producto/" in low:
                yield response.follow(href, callback=self.parse)
            elif "sitemap" in low and low.endswith(".xml"):
                # Seguir solo sitemaps útiles, para evitar páginas no relacionadas.
                if any(x in low for x in ["product", "category", "categoria", "sitemap_index"]):
                    yield response.follow(href, callback=self.parse)

    def parse_producto(self, response):
        if "/producto/" not in response.url:
            return

        nombre = self.extract_product_name(response)
        if not nombre:
            self.logger.warning(f"[{response.url}] producto sin nombre")
            return

        precio = self.extraer_precio(response)
        if precio is None or precio <= 0:
            self.logger.warning(f"[{response.url}] sin precio válido: {nombre}")
            return

        imagen = self.extraer_imagen(response)
        if imagen and "placeholder" in imagen.lower():
            imagen = ""

        categoria_origen = response.meta.get("categoria_origen", "").strip()
        categoria_web = self.extraer_categoria_producto(response)
        categoria = (
            extract_category(categoria_web)
            or extract_category(categoria_origen)
            or extract_category(nombre)
            or categoria_web
            or categoria_origen
            or "Otros"
        )

        marca = self.extraer_marca(response, nombre)
        descripcion = self.extraer_descripcion(response, nombre)
        stock = self.extraer_stock(response)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = marca or "Genérico"
        item["descripcion"] = descripcion

        yield self.normalizar_item(item)

    # -------------------- extracción de enlaces --------------------

    def extract_unique_links(self, response, selectors):
        vistos = set()
        links = []

        for selector in selectors:
            for href in response.css(selector).getall():
                href = self.clean_url(response.urljoin((href or "").strip()))
                if not href:
                    continue
                if "add-to-cart" in href.lower():
                    continue
                if href in vistos:
                    continue
                vistos.add(href)
                links.append(href)

        return links

    def clean_url(self, url):
        url = self.clean_text(url)
        if not url:
            return ""
        url = url.split("#")[0]
        url = re.sub(r"[?&](add-to-cart|attribute_|variation_id|quantity)=[^&]+", "", url, flags=re.I)
        url = url.split("?")[0]
        return url.rstrip("/") + ("/" if "/producto/" in url or "/categoria-producto/" in url else "")

    # -------------------- producto --------------------

    def extract_product_name(self, response):
        candidates = [
            response.css("h1.product_title::text").get(),
            response.css("h1.entry-title::text").get(),
            response.css(".summary h1::text").get(),
            response.css("meta[property='og:title']::attr(content)").get(),
            response.css("meta[name='twitter:title']::attr(content)").get(),
            response.css("title::text").get(default=""),
        ]

        for raw in candidates:
            nombre = self.clean_product_title(raw)
            if self.is_valid_product_name(nombre):
                return nombre

        return ""

    def clean_product_title(self, text):
        text = self.clean_text(text)
        if not text:
            return ""
        text = re.sub(r"\s*[–|-]\s*Full Office\s*$", "", text, flags=re.I)
        text = re.sub(r"\s+cantidad\s*$", "", text, flags=re.I)
        text = re.sub(r"\s+Comprar por WhatsApp\s*$", "", text, flags=re.I)
        return self.clean_text(text)

    def is_valid_product_name(self, nombre):
        low = self.clean_text(nombre).lower()
        if not low or len(low) < 4:
            return False
        if low in {"tienda", "nosotros", "carrito", "inicio", "categorías", "categorias"}:
            return False
        if "full office" == low:
            return False
        return True

    def extraer_precio(self, response):
        # Primero buscar solo dentro del bloque principal del producto para no agarrar relacionados.
        selectors = [
            ".summary p.price ::text",
            ".summary .price ::text",
            "p.price ::text",
            "meta[property='product:price:amount']::attr(content)",
            "meta[property='og:price:amount']::attr(content)",
        ]

        for selector in selectors:
            parts = [self.clean_text(x) for x in response.css(selector).getall() if self.clean_text(x)]
            if not parts:
                continue
            texto = " ".join(parts)
            valor = self.parse_precio(texto)
            if valor and valor >= 1000:
                return valor

        # Fallback controlado: cortar antes de recomendados/relacionados/footer.
        main_text = self.extract_main_product_text(response)
        valor = self.parse_precio(main_text)
        if valor and valor >= 1000:
            return valor

        return None

    def parse_precio(self, texto):
        if not texto:
            return None

        texto = self.clean_text(texto)

        patrones = [
            r"(?:Gs\.?|₲|PYG)\s*([\d\.\,]+)",
            r"([\d\.\,]+)\s*(?:Gs\.?|₲|PYG)",
        ]

        for patron in patrones:
            m = re.search(patron, texto, re.I)
            if m:
                numero = re.sub(r"[^\d]", "", m.group(1))
                if numero.isdigit():
                    return int(numero)

        # Para meta product:price:amount puede venir solo como 2990000 o 2990000.00
        if re.fullmatch(r"\d+(?:[\.,]\d{1,2})?", texto):
            numero = re.sub(r"[^\d]", "", texto.split(",")[0].split(".")[0] if len(texto.split(".")[-1]) <= 2 else texto)
            if numero.isdigit():
                return int(numero)

        return None

    def extraer_imagen(self, response):
        candidates = [
            response.css('meta[property="og:image"]::attr(content)').get(),
            response.css('meta[name="twitter:image"]::attr(content)').get(),
            response.css('.woocommerce-product-gallery__image img::attr(data-large_image)').get(),
            response.css('.woocommerce-product-gallery__image img::attr(src)').get(),
            response.css('.summary + * img::attr(src)').get(),
        ]

        for imagen in candidates:
            imagen = self.clean_text(imagen)
            if imagen and not imagen.startswith("data:image") and "placeholder" not in imagen.lower():
                return response.urljoin(imagen)

        for src in response.css('img::attr(src), img::attr(data-src)').getall():
            src = self.clean_text(src)
            if not src or src.startswith("data:image"):
                continue
            if "placeholder" in src.lower():
                continue
            if any(ext in src.lower() for ext in [".webp", ".jpg", ".jpeg", ".png"]):
                return response.urljoin(src)

        return ""

    def extraer_categoria_producto(self, response):
        cats = [self.clean_text(x) for x in response.css('.posted_in a::text, .woocommerce-breadcrumb a::text, nav.woocommerce-breadcrumb a::text').getall()]
        cats = [c for c in cats if c and c.lower() not in {"inicio", "tienda"}]
        if cats:
            return cats[-1]
        return ""

    def extraer_marca(self, response, nombre):
        # 1) WooCommerce attributes table.
        selectors = [
            '.woocommerce-product-attributes-item--attribute_pa_marca .woocommerce-product-attributes-item__value ::text',
            '.woocommerce-product-attributes-item--attribute_marca .woocommerce-product-attributes-item__value ::text',
            'tr[class*="marca"] td ::text',
            'tr[class*="brand"] td ::text',
        ]
        for selector in selectors:
            marca = self.clean_text(" ".join(response.css(selector).getall()))
            marca = self.clean_brand_value(marca)
            if marca:
                return marca

        # 2) Texto visible tipo: Marca Samsung.
        texts = [self.clean_text(t) for t in response.css("body ::text").getall() if self.clean_text(t)]
        for i, txt in enumerate(texts):
            low = txt.lower()
            if low == "marca" and i + 1 < len(texts):
                marca = self.clean_brand_value(texts[i + 1])
                if marca:
                    return marca
            m = re.match(r"^marca\s*:?\s*(.+)$", txt, flags=re.I)
            if m:
                marca = self.clean_brand_value(m.group(1))
                if marca:
                    return marca

        # 3) Utilidad general del proyecto.
        marca = self.clean_brand_value(extract_brand(nombre))
        return marca or "Genérico"

    def clean_brand_value(self, text):
        text = self.clean_text(text)
        if not text:
            return ""
        text = re.split(
            r"\b(EAN|EAN-13|Color|Garant[ií]a|Memoria|Tipo|Categor[ií]a|Etiqueta|Descripci[oó]n|Stock|Precio)\b",
            text,
            maxsplit=1,
            flags=re.I,
        )[0]
        text = self.clean_text(text)
        if not text or len(text) > 40:
            return ""
        if text.lower() in {"marca", "sin marca", "no brand", "n/a", "na"}:
            return ""
        return text

    def extraer_stock(self, response):
        main_text = self.extract_main_product_text(response).lower()
        if any(x in main_text for x in ["sin stock", "agotado", "out of stock", "no disponible"]):
            return "Consultar stock"
        if any(x in main_text for x in ["in stock", "en stock", "disponible"]):
            return "En stock"
        return "En stock"

    def extraer_descripcion(self, response, nombre=""):
        selectors = [
            "#tab-description *::text",
            ".woocommerce-Tabs-panel--description *::text",
            ".woocommerce-product-details__short-description *::text",
            ".summary .woocommerce-product-details__short-description *::text",
        ]

        for selector in selectors:
            descripcion = " ".join(
                self.clean_text(t)
                for t in response.css(selector).getall()
                if self.clean_text(t)
            )
            descripcion = self.clean_description(descripcion, nombre)
            if self.is_good_description(descripcion):
                return descripcion[:1200]

        # Fallback desde el texto principal de producto.
        descripcion = self.clean_description(self.extract_main_product_text(response), nombre)
        return descripcion[:1200] if self.is_good_description(descripcion) else ""

    def clean_description(self, text, nombre=""):
        text = self.clean_text(text)
        if not text:
            return ""

        if nombre:
            nombre_clean = self.clean_text(nombre)
            if text.lower().startswith(nombre_clean.lower()):
                text = text[len(nombre_clean):].strip(" -:|")

        cuts = [
            "hasta 12 cuotas",
            "añadir al carrito",
            "comprar por whatsapp",
            "categoría:",
            "etiquetas:",
            "también te recomendamos",
            "tambien te recomendamos",
            "productos relacionados",
            "datos de contacto",
            "seguinos",
            "términos y condiciones",
            "terminos y condiciones",
            "envíanos un mensaje",
            "envianos un mensaje",
            "carrito",
        ]
        low = text.lower()
        cut_at = len(text)
        for marker in cuts:
            pos = low.find(marker)
            if pos != -1:
                cut_at = min(cut_at, pos)
        text = text[:cut_at]

        text = re.sub(r"\bGs\.?\s*[\d\.\,]+\b", " ", text, flags=re.I)
        text = re.sub(r"\s+", " ", text).strip(" -:|")
        return text

    def is_good_description(self, text):
        if not text:
            return False
        low = text.lower()
        if len(low) < 25:
            return False
        bad = ["login", "register", "nombre de usuario", "contraseña", "datos de contacto"]
        return not any(x in low for x in bad)

    # -------------------- páginas/categorías --------------------

    def extract_page_category(self, response):
        raw = (
            response.css("h1.page-title::text").get()
            or response.css("h1::text").get()
            or response.css("title::text").get(default="")
        )
        raw = self.clean_text(raw)
        raw = re.sub(r"\s*[–|-]\s*Full Office\s*$", "", raw, flags=re.I)
        raw = re.sub(r"^Categor[ií]a:\s*", "", raw, flags=re.I)
        return raw

    def extract_main_product_text(self, response):
        parts = response.css(
            ".summary *::text, "
            ".woocommerce-tabs *::text, "
            "#tab-description *::text, "
            ".woocommerce-Tabs-panel--description *::text"
        ).getall()
        if not parts:
            parts = response.css("body ::text").getall()
        return self.clean_text(" ".join(t.strip() for t in parts if t.strip()))

    def normalizar_item(self, item):
        marca = self.clean_text(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = self.clean_text(item.get("categoria") or "")
        if not categoria or categoria.lower() in {"sin categoría", "sin categoria", "uncategorized", "productos"}:
            item["categoria"] = extract_category(item.get("nombre") or "") or "Otros"
        else:
            item["categoria"] = categoria

        item["descripcion"] = self.clean_text(item.get("descripcion") or "")[:1200]
        item["nombre"] = self.clean_product_title(item.get("nombre") or "")
        item["url"] = self.clean_url(item.get("url") or "")
        return item

    def clean_text(self, text):
        if not text:
            return ""
        text = re.sub(r"<[^>]+>", " ", str(text))
        text = text.replace("\xa0", " ")
        text = re.sub(r"\s+", " ", text)
        return text.strip(" -\n\t\r")
