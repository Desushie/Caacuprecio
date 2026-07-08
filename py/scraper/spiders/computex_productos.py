import html
import json
import re
from urllib.parse import urlparse

import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class ComputexProductosSpider(scrapy.Spider):
    name = "computex_productos"
    store_name = "Computex"
    allowed_domains = ["computex.com.py"]

    # Nueva estructura de Computex.
    # Ojo: la URL "aoutomotivos" está escrita así en el sitio/listado que pasaste.
    CATEGORY_URLS = {
        "https://computex.com.py/products-category/informatica-2/": "Informática",
        "https://computex.com.py/products-category/seguridad-2/": "Cámaras y Seguridad",
        "https://computex.com.py/products-category/aoutomotivos/": "Automotivos",
        "https://computex.com.py/products-category/muebles-2/": "Hogar",
        "https://computex.com.py/products-category/electronica-2/": "Electrónica",
        "https://computex.com.py/products-category/sonido-2/": "Audio",
        "https://computex.com.py/products-category/gamer-2/": "Gaming",
        "https://computex.com.py/products-category/instrumento-musical/": "Instrumentos Musicales",
    }

    # Páginas de descubrimiento. La versión anterior dependía solo de CATEGORY_URLS;
    # si Computex agrega una categoría/subcategoría nueva, quedaba afuera del scraping.
    DISCOVERY_URLS = [
        "https://computex.com.py/",
        "https://computex.com.py/productos/",
        "https://computex.com.py/tienda/",
        "https://computex.com.py/shop/",
    ]

    # Sitemaps habituales en WordPress/WooCommerce. Si alguno devuelve 404, Scrapy lo ignora.
    SITEMAP_URLS = [
        "https://computex.com.py/sitemap.xml",
        "https://computex.com.py/product-sitemap.xml",
        "https://computex.com.py/product_cat-sitemap.xml",
        "https://computex.com.py/page-sitemap.xml",
    ]

    # WooCommerce Store API pública. Ayuda a encontrar productos que no aparecen
    # en las categorías visibles o que están cargados por AJAX.
    STORE_API_URL = "https://computex.com.py/wp-json/wc/store/v1/products?per_page=100&page=1"

    start_urls = list(CATEGORY_URLS.keys()) + DISCOVERY_URLS + SITEMAP_URLS + [STORE_API_URL]

    custom_settings = {
        # Evita reintentos demasiado agresivos si alguna categoría temporalmente no responde.
        "RETRY_HTTP_CODES": [500, 502, 503, 504, 522, 524, 408, 429],
    }

    def build_start_requests(self):
        """Genera requests iniciales compatible con Scrapy viejo y nuevo."""
        vistos = set()

        for url, categoria_raw in self.CATEGORY_URLS.items():
            if url in vistos:
                continue
            vistos.add(url)
            yield scrapy.Request(
                url,
                callback=self.parse,
                meta={"categoria_raw": categoria_raw},
                dont_filter=True,
            )

        for url in self.DISCOVERY_URLS:
            if url in vistos:
                continue
            vistos.add(url)
            yield scrapy.Request(url, callback=self.parse, dont_filter=True)

        for url in self.SITEMAP_URLS:
            if url in vistos:
                continue
            vistos.add(url)
            yield scrapy.Request(
                url,
                callback=self.parse_sitemap,
                errback=self.ignore_request_error,
                dont_filter=True,
            )

        if self.STORE_API_URL not in vistos:
            yield scrapy.Request(
                self.STORE_API_URL,
                callback=self.parse_store_api,
                errback=self.ignore_request_error,
                dont_filter=True,
            )

    async def start(self):
        """Scrapy 2.13+ usa start() en lugar de start_requests()."""
        for request in self.build_start_requests():
            yield request

    def start_requests(self):
        """Compatibilidad con versiones anteriores de Scrapy."""
        yield from self.build_start_requests()

    def ignore_request_error(self, failure):
        self.logger.debug("Request inicial ignorada: %s", failure.request.url)

    def parse(self, response):
        categoria_raw = response.meta.get("categoria_raw") or self.extraer_categoria_listado(response)

        # 0) Descubrir categorías/subcategorías nuevas.
        categorias_vistas = set()
        categorias_encontradas = 0
        for a in response.css('a[href*="/products-category/"], a[href*="/product-category/"]'):
            href = a.attrib.get("href") or ""
            url_cat = self.limpiar_url(response.urljoin(href))
            if not self.es_url_categoria(url_cat):
                continue
            if url_cat in categorias_vistas:
                continue

            categorias_vistas.add(url_cat)
            categorias_encontradas += 1
            texto_cat = self.limpiar_texto(" ".join(a.css("::text").getall()))
            yield response.follow(
                url_cat + "/",
                callback=self.parse,
                meta={"categoria_raw": texto_cat or self.categoria_desde_url(url_cat)},
            )

        # 1) Productos: en la estructura nueva siguen usando /productos/<slug>/
        vistos = set()
        productos_encontrados = 0
        for href in response.css('a[href*="/productos/"], a[href*="/producto/"]'):
            href = href.attrib.get("href") or ""
            url = self.limpiar_url(response.urljoin(href))

            if not self.es_url_producto(url):
                continue

            if url in vistos:
                continue

            vistos.add(url)
            productos_encontrados += 1

            # Computex redirige /productos/slug -> /productos/slug/.
            # Agregar la barra evita un 301 por cada producto.
            request_url = url + "/"

            yield response.follow(
                request_url,
                callback=self.parse_producto,
                meta={"categoria_raw": categoria_raw},
            )

        self.logger.warning(
            "[%s] productos encontrados: %s | categorías encontradas: %s | categoría: %s",
            response.url,
            productos_encontrados,
            categorias_encontradas,
            categoria_raw,
        )

        # 2) Paginación: /products-category/<categoria>/page/2/
        next_page = self.extraer_siguiente_pagina(response)
        if next_page:
            yield response.follow(
                next_page,
                callback=self.parse,
                meta={"categoria_raw": categoria_raw},
            )

    def parse_sitemap(self, response):
        """Lee sitemap.xml, product-sitemap.xml y product_cat-sitemap.xml."""
        locs = response.xpath('//*[local-name()="loc"]/text()').getall()
        if not locs:
            # Algunos servidores entregan XML como texto plano. Fallback por regex.
            locs = re.findall(r"<loc>\s*([^<]+?)\s*</loc>", response.text or "", flags=re.I)

        productos = 0
        categorias = 0
        sitemaps = 0

        for raw_url in locs:
            url = self.limpiar_url(raw_url)
            if not url:
                continue

            url_lower = url.lower()

            if url_lower.endswith(".xml") or "sitemap" in url_lower and not self.es_url_producto(url):
                sitemaps += 1
                yield response.follow(
                    url,
                    callback=self.parse_sitemap,
                    errback=self.ignore_request_error,
                )
                continue

            if self.es_url_producto(url):
                productos += 1
                yield response.follow(
                    url + "/",
                    callback=self.parse_producto,
                    meta={"categoria_raw": ""},
                )
                continue

            if self.es_url_categoria(url):
                categorias += 1
                yield response.follow(
                    url + "/",
                    callback=self.parse,
                    meta={"categoria_raw": self.categoria_desde_url(url)},
                )

        self.logger.warning(
            "[%s] sitemap: %s productos, %s categorías, %s sitemaps",
            response.url,
            productos,
            categorias,
            sitemaps,
        )

    def parse_store_api(self, response):
        """Lee la API pública de WooCommerce Store si está habilitada."""
        try:
            data = json.loads(response.text or "[]")
        except Exception:
            self.logger.debug("Store API no devolvió JSON válido: %s", response.url)
            return

        if not isinstance(data, list):
            return

        productos = 0
        for product in data:
            if not isinstance(product, dict):
                continue

            permalink = product.get("permalink") or product.get("url")
            if not permalink and product.get("slug"):
                permalink = f"https://computex.com.py/productos/{product.get('slug')}/"

            url = self.limpiar_url(permalink)
            if not self.es_url_producto(url):
                continue

            productos += 1
            categoria_raw = self.categoria_desde_api_product(product)
            yield response.follow(
                url + "/",
                callback=self.parse_producto,
                meta={"categoria_raw": categoria_raw},
            )

        self.logger.warning("[%s] Store API productos encontrados: %s", response.url, productos)

        # Si la API devolvió 100, probablemente hay otra página. Seguimos hasta que devuelva menos.
        if len(data) >= 100:
            page_match = re.search(r"[?&]page=(\d+)", response.url)
            page = int(page_match.group(1)) if page_match else 1
            next_url = re.sub(r"([?&]page=)\d+", rf"\g<1>{page + 1}", response.url)
            yield scrapy.Request(
                next_url,
                callback=self.parse_store_api,
                errback=self.ignore_request_error,
            )

    def categoria_desde_api_product(self, product):
        categorias = product.get("categories") or []
        if isinstance(categorias, list):
            for cat in categorias:
                if isinstance(cat, dict):
                    nombre = self.limpiar_texto(cat.get("name"))
                    if nombre:
                        return nombre
        return ""

    def parse_producto(self, response):
        nombre = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css(".product_title::text").get()
            or response.css(".entry-title::text").get(default="")
        )

        if not nombre or nombre.lower() == "productos":
            return

        body_text = " ".join(t.strip() for t in response.css("body ::text").getall() if t.strip())
        stock = self.extraer_stock(response, body_text)

        # No buscar el precio en todo el body de forma genérica: ahí aparecen
        # costos de delivery como Gs. 15.000. En Computex el precio real suele
        # aparecer como texto: "Precio: ₲ 345.000". Por eso extraemos solo el
        # bloque que sigue a "Precio:" y cortamos antes de "Envío".
        precio = self.extraer_precio_producto(response, body_text=body_text)

        if precio is None:
            # Si está agotado o no tiene precio real visible, no uses el delivery
            # como precio. Guardamos 0 y el stock queda como Consultar stock.
            precio = 0
            if stock == "En stock":
                stock = "Consultar stock"

        imagen = self.extraer_imagen(response)
        marca = extract_brand(nombre)

        categoria_raw = (
            response.meta.get("categoria_raw")
            or self.extraer_categoria(response, nombre)
            or ""
        )
        slug = response.url.rstrip("/").split("/")[-1].replace("-", " ")

        categoria = self.categoria_final_computex(
            nombre=nombre,
            categoria_raw=categoria_raw,
            marca=marca,
            slug=slug,
        )

        descripcion = self.extraer_descripcion(response)
        if not descripcion:
            self.logger.debug("Producto sin descripción visible: %s", response.url)

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio
        item["url"] = self.limpiar_url(response.url)
        item["categoria"] = categoria
        item["tienda"] = self.store_name

        item["stock"] = stock

        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion

        item = self.normalizar_item(item)

        yield item

    def extraer_stock(self, response, body_text=""):
        """Devuelve 'En stock' o 'Consultar stock' usando solo zonas cercanas al producto.

        No se usa el body completo porque puede contener productos relacionados agotados
        y marcar falsamente como agotada una ficha que sí tiene stock.
        """
        textos = []

        # 1) Clases típicas de WooCommerce/Elementor para disponibilidad.
        for sel in [
            ".stock ::text", ".stock::text",
            ".availability ::text", ".availability::text",
            ".out-of-stock ::text", ".out-of-stock::text",
            ".in-stock ::text", ".in-stock::text",
            ".summary .stock ::text", ".summary .stock::text",
            ".entry-summary .stock ::text", ".entry-summary .stock::text",
        ]:
            textos.extend(response.css(sel).getall())

        stock_text = self.limpiar_texto(" ".join(t for t in textos if t and t.strip()))
        texto = self.normalizar_simple(stock_text)

        negativos = [
            "agotado", "sin stock", "fuera de stock", "no disponible",
            "consultar stock", "out of stock", "sold out",
        ]
        positivos = [
            "en stock", "disponible", "hay stock", "in stock",
        ]

        if texto and any(p in texto for p in negativos):
            return "Consultar stock"
        if texto and any(p in texto for p in positivos):
            return "En stock"

        # 2) Fallback limitado al bloque del resumen, nunca al body completo.
        resumen = self.texto_producto_para_precio(response)
        resumen_norm = self.normalizar_simple(resumen)
        if resumen_norm and any(p in resumen_norm for p in negativos):
            return "Consultar stock"

        return "En stock"

    def extraer_precio_producto(self, response, body_text=""):
        """Extrae el precio real del producto sin confundirlo con delivery/envío.

        Computex suele renderizar el precio como texto visible cerca de la etiqueta
        "Precio". A veces no hay dos puntos: puede venir como "Precio ₲ 345.000".
        Por eso se revisan primero los textos cercanos a esa etiqueta y se corta
        antes de bloques de envío/delivery.
        """
        candidatos = []

        # 0) Caso más importante: buscar la etiqueta visible Precio/Precio: y leer
        # los textos inmediatamente siguientes, sin entrar al bloque de envío.
        precio_marcado = self.extraer_precio_desde_nodos_texto(response)
        if precio_marcado is not None:
            return precio_marcado

        # 0b) Fallback sobre el texto unido, aceptando "Precio" con o sin dos puntos.
        precio_marcado = self.extraer_precio_por_etiqueta(body_text)
        if precio_marcado is not None:
            return precio_marcado

        # 1) Meta tags y microdata: cuando existen suelen ser el precio real.
        for sel in [
            'meta[property="product:price:amount"]::attr(content)',
            'meta[property="og:price:amount"]::attr(content)',
            'meta[itemprop="price"]::attr(content)',
            '[itemprop="price"]::attr(content)',
            '[data-price]::attr(data-price)',
            '[data-product-price]::attr(data-product-price)',
        ]:
            for raw in response.css(sel).getall():
                raw = self.limpiar_texto(raw)
                if raw:
                    candidatos.append(raw)

        # 2) JSON-LD Product offers.
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            candidatos.extend(self._buscar_precios_jsonld(data))

        # 3) Bloques reales de precio. Se usa *::text porque WooCommerce suele
        # separar el símbolo ₲/Gs. y el monto en nodos diferentes.
        price_block_selectors = [
            '.summary p.price',
            '.summary .price',
            '.entry-summary p.price',
            '.entry-summary .price',
            '.woocommerce-variation-price',
            '.woocommerce-Price-amount',
            '.elementor-widget-woocommerce-product-price',
            '.elementor-widget-wc-product-price',
            '.jet-woo-builder-single-price',
            '.jet-woo-product-price',
            '.product-price',
            '.price',
        ]

        for sel in price_block_selectors:
            for block in response.css(sel):
                texto = self.texto_de_bloque(block)
                if not texto:
                    continue
                if self.bloque_es_envio_o_delivery(texto):
                    continue
                candidatos.append(texto)

        # 4) Fallback controlado: mirar solo el resumen cercano al producto,
        # recortando antes de envío/delivery. No usamos body completo.
        resumen = self.texto_producto_para_precio(response)
        if resumen:
            candidatos.append(resumen)

        for raw in candidatos:
            precio = self.parse_precio(raw)
            if precio is None:
                precio = self._precio_a_int(raw)

            # Evitar costos típicos de envío si de alguna forma pasaron el filtro.
            if precio in {10000, 15000, 20000, 25000, 30000} and self.bloque_es_envio_o_delivery(raw):
                continue

            if precio is not None and 1000 <= precio <= 500_000_000:
                return precio

        self.logger.debug("Precio no detectado en %s | textos precio: %r", response.url, self.debug_textos_precio(response))
        return None

    def extraer_precio_desde_nodos_texto(self, response):
        """Busca precio en los nodos de texto alrededor de la palabra Precio.

        Esto cubre estructuras donde el HTML separa la etiqueta y el monto:
        ['Precio', '₲', '345.000', 'Envío', 'Delivery Caacupe', '₲ 15.000'].
        """
        textos = [self.limpiar_texto(t) for t in response.css('body ::text').getall()]
        textos = [t for t in textos if t]
        if not textos:
            return None

        cortes = [
            'envio', 'envios', 'delivery', 'transportadora', 'del local gratis',
            'retiro del local', 'medios de pago', 'formas de pago',
            'descripcion', 'descripción', 'productos relacionados',
            'tambien te puede gustar', 'también te puede gustar',
            'añadir al carrito', 'agregar al carrito', 'comprar',
        ]

        for i, texto in enumerate(textos):
            norm = self.normalizar_simple(texto)

            # Acepta "Precio", "Precio:", "Precio ₲ 345.000".
            if not re.search(r'\bprecio\b', norm):
                continue
            if any(x in norm for x in ['precio de envio', 'precio envio', 'opciones de envio']):
                continue

            ventana = []
            for t in textos[i:i + 12]:
                tn = self.normalizar_simple(t)
                if ventana and any(c in tn for c in cortes):
                    break
                ventana.append(t)

            chunk = self.limpiar_texto(' '.join(ventana))
            if self.bloque_es_envio_o_delivery(chunk):
                # Si el bloque contiene precio y envío, cortar antes del envío.
                chunk = self.recortar_antes_de_envio(chunk)

            precio = self.extraer_precio_por_etiqueta(chunk)
            if precio is None:
                precio = self.parse_precio(chunk)
            if precio is not None and 1000 <= precio <= 500_000_000:
                return precio

        return None

    def recortar_antes_de_envio(self, texto):
        texto = self.limpiar_texto(texto)
        corte = re.search(
            r'\b(opciones\s+de\s+env[ií]o|env[ií]o|delivery|transportadora|del\s+local\s+gratis|'
            r'retiro\s+del\s+local|medios\s+de\s+pago|formas\s+de\s+pago)\b',
            texto,
            flags=re.IGNORECASE,
        )
        if corte:
            return self.limpiar_texto(texto[:corte.start()])
        return texto

    def debug_textos_precio(self, response):
        textos = [self.limpiar_texto(t) for t in response.css('body ::text').getall()]
        textos = [t for t in textos if t]
        partes = []
        for i, t in enumerate(textos):
            if 'precio' in self.normalizar_simple(t):
                partes.append(' | '.join(textos[i:i + 10]))
        return ' || '.join(partes)[:500]

    def texto_de_bloque(self, selector):
        """Une textos y atributos útiles de un bloque selector CSS."""
        partes = []
        partes.extend(selector.css('::text').getall())
        partes.extend(selector.css('*::text').getall())

        # Algunos themes guardan el precio en data-price/data-product-price.
        for attr in ['content', 'data-price', 'data-product-price', 'data-value']:
            val = selector.attrib.get(attr)
            if val:
                partes.append(val)

        texto = ' '.join(self.limpiar_texto(p) for p in partes if self.limpiar_texto(p))
        return self.limpiar_texto(texto)

    def extraer_precio_por_etiqueta(self, texto):
        """Busca precios detrás de la etiqueta Precio, con o sin dos puntos."""
        texto = self.limpiar_texto(texto)
        if not texto:
            return None

        # Recortar primero si el bloque mezcló precio con envío/delivery.
        texto = self.recortar_antes_de_envio(texto)

        patrones = [
            # Precio: ₲ 345.000 / Precio ₲ 345.000 / Precio Gs. 345.000
            r"precio(?:\s+(?:regular|normal|de\s+venta))?\s*[:：]?\s*(.{0,120})",
        ]

        cortes = (
            "envío", "envio", "delivery", "transportadora", "descripción",
            "descripcion", "también te puede gustar", "tambien te puede gustar",
            "productos relacionados", "añadir al carrito", "agregar al carrito",
        )

        for patron in patrones:
            for match in re.finditer(patron, texto, flags=re.IGNORECASE):
                chunk = match.group(1)

                chunk_low = chunk.lower()
                cut_at = len(chunk)
                for marker in cortes:
                    pos = chunk_low.find(marker.lower())
                    if pos != -1:
                        cut_at = min(cut_at, pos)
                chunk = chunk[:cut_at]

                if self.bloque_es_envio_o_delivery(chunk):
                    continue

                precio = self.parse_precio(chunk)
                if precio is not None and 1000 <= precio <= 500_000_000:
                    return precio

                # Algunos HTML separan símbolo y número o dejan solo el número después de Precio.
                precio = self._precio_a_int(chunk)
                if precio is not None and 1000 <= precio <= 500_000_000:
                    return precio

        return None

    def bloque_es_envio_o_delivery(self, texto):
        texto_norm = self.normalizar_simple(texto)
        basura_envio = [
            'delivery',
            'envio',
            'envios',
            'opciones de envio',
            'calcular envio',
            'transportadora',
            'del local gratis',
            'retiro del local',
            'costo de envio',
            'costo envio',
        ]
        return any(x in texto_norm for x in basura_envio)

    def _buscar_precios_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            tipo = data.get('@type')
            tipos = tipo if isinstance(tipo, list) else [tipo]

            if any(str(t).lower() == 'product' for t in tipos if t):
                offers = data.get('offers')
                encontrados.extend(self._precios_desde_offers(offers))

            for v in data.values():
                encontrados.extend(self._buscar_precios_jsonld(v))

        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_precios_jsonld(item))

        return encontrados

    def _precios_desde_offers(self, offers):
        precios = []
        if isinstance(offers, dict):
            for key in ['price', 'lowPrice', 'highPrice']:
                value = offers.get(key)
                if value not in (None, ''):
                    precios.append(str(value))
            if 'offers' in offers:
                precios.extend(self._precios_desde_offers(offers.get('offers')))
        elif isinstance(offers, list):
            for offer in offers:
                precios.extend(self._precios_desde_offers(offer))
        return precios

    def texto_producto_para_precio(self, response):
        """Fallback limitado a la zona de producto y cortado antes de envío/delivery."""
        partes = []
        for sel in [
            '.summary ::text',
            '.entry-summary ::text',
            '.product .summary ::text',
            '.product .entry-summary ::text',
            '.elementor-widget-woocommerce-product-price ::text',
            '.elementor-widget-wc-product-price ::text',
            '.jet-woo-builder-single-price ::text',
            '.jet-woo-product-price ::text',
        ]:
            partes.extend(response.css(sel).getall())

        texto = ' '.join(t.strip() for t in partes if t and t.strip())
        texto = self.limpiar_texto(texto)
        if not texto:
            return ''

        # Cortar sobre el texto original con regex case-insensitive para no mezclar
        # índices de texto normalizado con índices del texto original.
        corte = re.search(
            r'\b(opciones\s+de\s+env[ií]o|delivery|transportadora|del\s+local\s+gratis|'
            r'calcular\s+env[ií]o|medios\s+de\s+pago|formas\s+de\s+pago|'
            r'añadir\s+al\s+carrito|agregar\s+al\s+carrito)\b',
            texto,
            flags=re.IGNORECASE,
        )
        if corte:
            texto = texto[:corte.start()]

        return self.limpiar_texto(texto)

    def limpiar_url(self, url):
        url = (url or "").strip()
        if not url:
            return ""

        url = url.split("#")[0].split("?")[0].rstrip("/")
        return url

    def es_url_producto(self, url):
        if not url:
            return False

        parsed = urlparse(url)
        path = parsed.path.strip("/").lower()

        if parsed.netloc and "computex.com.py" not in parsed.netloc:
            return False

        if path == "productos":
            return False

        if not path.startswith("productos/"):
            return False

        if "/page/" in path:
            return False

        # Evita adjuntos o rutas raras.
        partes = [p for p in path.split("/") if p]
        return len(partes) == 2 and bool(partes[-1])

    def es_url_categoria(self, url):
        if not url:
            return False

        parsed = urlparse(url)
        path = parsed.path.strip("/").lower()

        if parsed.netloc and "computex.com.py" not in parsed.netloc:
            return False

        if "/page/" in path:
            return False

        return path.startswith("products-category/") or path.startswith("product-category/")

    def categoria_desde_url(self, url):
        path = urlparse(url).path.strip("/")
        partes = [p for p in path.split("/") if p]
        if not partes:
            return ""
        slug = partes[-1]
        slug = re.sub(r"-\d+$", "", slug)
        return self.limpiar_texto(slug.replace("-", " ").title())

    def extraer_siguiente_pagina(self, response):
        # WooCommerce/WordPress a veces usa rel=next.
        href = response.css('a[rel="next"]::attr(href)').get()
        if href:
            return href

        for a in response.css("a"):
            text = " ".join(t.strip() for t in a.css("::text").getall() if t.strip())
            href = (a.attrib.get("href") or "").strip()
            text_l = text.lower()

            if href and (
                "página siguiente" in text_l
                or "pagina siguiente" in text_l
                or "siguiente" == text_l
                or "next" == text_l
                or ">>" in text_l
            ):
                return href

        return None

    def extraer_categoria_listado(self, response):
        titulo = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css(".page-title::text").get(default="")
        )
        titulo = re.sub(r"^todo\s+", "", titulo, flags=re.IGNORECASE).strip()
        return titulo
    def limpiar_descripcion(self, texto):
        texto = self.limpiar_texto(texto)
        if not texto:
            return ""

        # Si el selector amplio trajo la ficha completa, quedarse solo con
        # lo que viene después del marcador real de descripción.
        texto = self.recortar_descripcion_desde_marcador(texto)

        # Quitar encabezados comunes al inicio.
        texto = re.sub(
            r'^(especificaciones\s+generales\s*[:.\-–—]?\s*)',
            '',
            texto,
            flags=re.IGNORECASE
        )

        texto = re.sub(
            r'^(descripci[oó]n(?:\s+del\s+producto)?\s*[:.\-–—]?\s*)',
            '',
            texto,
            flags=re.IGNORECASE
        )

        texto = re.sub(
            r'^(caracter[ií]sticas(?:\s+del\s+producto)?\s*[:.\-–—]?\s*)',
            '',
            texto,
            flags=re.IGNORECASE
        )

        # Quitar restos visuales del sitio que aparecen antes de la descripción
        # cuando el fallback toma texto de .summary/.entry-summary.
        texto = re.sub(r'^ver\s+m[áa]s\s+de\s+cerca(?:\s*/\s*\d+)?\s*', '', texto, flags=re.IGNORECASE)
        texto = re.sub(r'^(?:/\s*\d+\s*)+', '', texto)
        texto = re.sub(
            r'^(?:automotivos|inform[áa]tica|seguridad|muebles|electr[oó]nica|sonido|gamer|instrumento musical|car audio)\s+',
            '',
            texto,
            flags=re.IGNORECASE
        )
        texto = re.sub(
            r'^(?:precio\s+)?opciones\s+de\s+env[ií]o.*?(?:transportadora\s+a\s+todo\s+el\s+pa[ií]s)\s*',
            '',
            texto,
            flags=re.IGNORECASE
        )
        texto = re.sub(r'^(?:buscar\s+)?del\s+local\s+gratis.*?(?:pa[ií]s)\s*', '', texto, flags=re.IGNORECASE)

        # Limpiar markdown/HTML textual y separadores raros.
        texto = texto.replace('**', '')
        # Quitar asteriscos usados como viñetas, sin romper medidas tipo 5mm*5mts.
        texto = re.sub(r'(?<!\w)\*\s*', '', texto)
        texto = re.sub(r'\s*[|•·]+\s*', ' ', texto)
        texto = re.sub(r'\s+', ' ', texto).strip(" -–—:;,")

        # Algunos bloques amplios pegan un fragmento de precio al final:
        # ejemplo: "35KHz.450". Evitamos borrar versiones técnicas tipo 5.0.
        texto = re.sub(r'(?<=[A-Za-zÁÉÍÓÚáéíóúñ])\.(\d{3,6})$', '', texto).strip()

        return texto[:1000]

    def recortar_descripcion_desde_marcador(self, texto):
        """
        Computex mezcla en algunos productos: galería, categoría, precio,
        envío y luego "Descripción del producto". Si aparece ese marcador,
        descartamos todo lo anterior.
        """
        texto = self.limpiar_texto(texto)
        if not texto:
            return ""

        marcadores = [
            r'descripci[oó]n\s+del\s+producto',
            r'detalles?\s+del\s+producto',
            r'caracter[ií]sticas\s+del\s+producto',
            r'especificaciones\s+del\s+producto',
            r'ficha\s+t[eé]cnica',
            r'informaci[oó]n\s+del\s+producto',
        ]

        for patron in marcadores:
            match = re.search(patron + r'\s*[:.\-–—]?\s*', texto, flags=re.IGNORECASE)
            if match:
                return texto[match.end():].strip()

        return texto

    def limpiar_descripcion_final(self, texto, nombre_producto=None):
        """Limpieza final: recorta UI, quita el título repetido y valida."""
        texto = self.limpiar_descripcion(texto)
        if not texto:
            return ""

        nombre_producto = self.limpiar_texto(nombre_producto or "")
        if nombre_producto:
            # Quitar el título si aparece pegado al inicio o al final de la descripción.
            variantes = {
                nombre_producto,
                nombre_producto.replace("×", "x"),
                nombre_producto.replace("x", "×"),
                nombre_producto.replace("″", '"'),
                nombre_producto.replace("”", '"'),
                nombre_producto.replace("“", '"'),
                nombre_producto.replace("'", ""),
                nombre_producto.replace('"', ""),
                nombre_producto.replace("″", ""),
            }
            for nombre in sorted(variantes, key=len, reverse=True):
                nombre = self.limpiar_texto(nombre)
                if not nombre:
                    continue
                patron = re.escape(nombre).replace(r'\ ', r'\s+')
                texto = re.sub(rf'^\s*{patron}\s*[-–—:]?\s*', '', texto, flags=re.IGNORECASE)
                texto = re.sub(rf'\s*[-–—:]?\s*{patron}\s*$', '', texto, flags=re.IGNORECASE)

        texto = self.limpiar_descripcion(texto)
        if self.descripcion_es_solo_titulo(texto, nombre_producto):
            return ""
        return texto

    def categoria_final_computex(self, nombre="", categoria_raw="", marca="", slug=""):
        """Devuelve solo categorías maestras para Computex.

        Computex muestra categorías del menú con textos como
        "PUNTO DE VENTAS 16 productos" o "Calculadora 2 productos".
        Si guardamos eso directo, el pipeline crea categorías basura.
        """
        nombre = self.limpiar_texto(nombre)
        marca = self.limpiar_texto(marca)
        slug = self.limpiar_texto(slug)
        categoria_limpia = self.limpiar_categoria_computex(categoria_raw)

        # 1) Primero dejar que tu normalizador categorice por nombre del producto.
        # Esto evita que una categoría muy amplia como "Automotivos" arrastre mal
        # productos que por nombre son Audio, Accesorios, Herramientas, etc.
        for candidato in [
            extract_category(nombre, categoria_limpia, marca),
            extract_category(slug, categoria_limpia, marca),
            self.mapear_categoria_computex(categoria_limpia),
            extract_category(categoria_limpia, "", marca),
        ]:
            candidato = self.limpiar_texto(candidato)
            if self.categoria_maestra_valida(candidato):
                return candidato

        return "Productos"

    def limpiar_categoria_computex(self, categoria):
        """Limpia categoría cruda del menú: quita conteos y encabezados."""
        categoria = self.limpiar_texto(categoria)
        if not categoria:
            return ""

        # Ejemplos:
        # "PUNTO DE VENTAS 16 productos" -> "PUNTO DE VENTAS"
        # "Calculadora 2 productos" -> "Calculadora"
        # "UKELELE 1 producto" -> "UKELELE"
        categoria = re.sub(r"\s*\(?\d+\s*(?:productos?|items?|art[ií]culos?)\)?\s*$", "", categoria, flags=re.I)
        categoria = re.sub(r"\s+\d+\s*$", "", categoria)
        categoria = re.sub(r"^todo\s+", "", categoria, flags=re.I)
        categoria = re.sub(r"\s+", " ", categoria).strip(" -–—:;,")

        # Encabezados del menú, no categorías reales.
        if self.normalizar_categoria_key(categoria) in {
            "categorias populares",
            "categoria populares",
            "categorias",
            "categoria",
            "populares",
        }:
            return ""

        return categoria

    def normalizar_categoria_key(self, texto):
        texto = self.normalizar_simple(texto)
        texto = re.sub(r"[^a-z0-9\s]", " ", texto)
        texto = re.sub(r"\s+", " ", texto).strip()
        return texto

    def mapear_categoria_computex(self, categoria):
        """Mapea subcategorías propias de Computex a categorías maestras."""
        key = self.normalizar_categoria_key(categoria)
        if not key:
            return ""

        mapa = {
            "informatica": "Informática",
            "informatica 2": "Informática",
            "punto de ventas": "Informática",
            "puntos de ventas": "Informática",
            "pos": "Informática",

            "seguridad": "Cámaras y Seguridad",
            "seguridad 2": "Cámaras y Seguridad",
            "fotografia y videos": "Cámaras y Seguridad",
            "fotografia y video": "Cámaras y Seguridad",
            "camaras": "Cámaras y Seguridad",

            "sonido": "Audio",
            "sonido 2": "Audio",
            "car audio": "Audio",
            "discoteca": "Audio",
            "cuerdas": "Audio",
            "ukelele": "Audio",
            "instrumento musical": "Audio",
            "instrumentos musicales": "Audio",

            "gamer": "Gaming",
            "gaming": "Gaming",

            "muebles": "Hogar",
            "muebles 2": "Hogar",
            "hogar": "Hogar",

            "televisores": "TV y Video",
            "televisor": "TV y Video",
            "tv": "TV y Video",

            "calculadora": "Oficina",
            "calculadoras": "Oficina",
            "oficina": "Oficina",

            "filtro de linea": "Accesorios",
            "filtros de linea": "Accesorios",
            "relojes": "Accesorios",
            "reloj": "Accesorios",
            "automotivos": "Accesorios",
            "automotivo": "Accesorios",
            "electronica": "Accesorios",
            "electronicos": "Accesorios",
        }

        return mapa.get(key, "")

    def categoria_maestra_valida(self, categoria):
        categoria = self.limpiar_texto(categoria)
        if not categoria:
            return False
        if categoria.lower() in {"productos", "producto", "sin categoría", "sin categoria", "uncategorized"}:
            return False

        categorias_validas = {
            "Celulares y Smartphones",
            "Tablets",
            "Informática",
            "Audio",
            "TV y Video",
            "Gaming",
            "Accesorios",
            "Redes y Conectividad",
            "Cámaras y Seguridad",
            "Electrodomésticos",
            "Climatización",
            "Herramientas",
            "Oficina",
            "Hogar",
            "Salud y Belleza",
            "Deportes",
            "Motocicletas",
            "Bebés y Juguetes",
            "Outlet",
            "Productos",
        }
        return categoria in categorias_validas

    def normalizar_item(self, item):
        marca = self.limpiar_texto(item.get("marca") or "")
        if not marca or marca.lower() in {"sin marca", "no brand", "n/a", "na"}:
            item["marca"] = "Genérico"
        else:
            item["marca"] = marca

        categoria = self.limpiar_texto(item.get("categoria") or "")
        if not categoria or categoria.lower() in {
            "sin categoría", "sin categoria", "uncategorized", "productos", "producto"
        }:
            categoria = self.limpiar_texto(extract_category(item.get("nombre") or "")) or "Otros"
        item["categoria"] = categoria

        item["descripcion"] = self.limpiar_descripcion_final(
            item.get("descripcion") or "",
            item.get("nombre") or ""
        )

        return item

    def limpiar_texto(self, texto):
        if not texto:
            return ""
        texto = re.sub(r"\s+", " ", texto)
        return texto.strip(" -\n\t\r")

    def extraer_categoria(self, response, nombre):
        candidatos = []

        ignorar = {
            "productos", "producto", "tienda", "shop", "inicio", "home",
            "sin categoria", "sin categoría", "uncategorized"
        }

        def agregar_candidato(valor):
            valor = self.limpiar_texto(valor)
            if not valor:
                return
            if valor.lower() in ignorar:
                return
            if len(valor) < 3:
                return
            candidatos.append(valor)

        # 1. Breadcrumbs
        for t in response.css(
            '.woocommerce-breadcrumb a::text, '
            '.breadcrumb a::text, '
            'nav.woocommerce-breadcrumb a::text, '
            '[class*="breadcrumb"] a::text'
        ).getall():
            agregar_candidato(t)

        # 2. Categorías visibles / taxonomías
        for t in response.css(
            '.posted_in a::text, '
            '.product_meta .posted_in a::text, '
            '.product-categories a::text'
        ).getall():
            agregar_candidato(t)

        # 3. Meta keywords
        meta_keywords = response.css('meta[name="keywords"]::attr(content)').get() or ""
        for part in re.split(r"[,|]", meta_keywords):
            agregar_candidato(part)

        # 4. JSON-LD
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            for cat in self._buscar_categorias_jsonld(data):
                agregar_candidato(cat)

        # 5. Slug URL
        slug = response.url.rstrip("/").split("/")[-1]
        agregar_candidato(slug.replace("-", " "))

        # 6. Nombre del producto como respaldo
        cat_nombre = self.limpiar_texto(extract_category(nombre))
        if cat_nombre:
            return cat_nombre

        # 7. Elegir mejor candidato
        for cat in candidatos:
            cat_extraida = self.limpiar_texto(extract_category(cat))
            if cat_extraida and cat_extraida.lower() not in ignorar:
                return cat_extraida

        for cat in candidatos:
            return cat

        return "Otros"

    def _buscar_categorias_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            for k, v in data.items():
                if k.lower() in {"category", "articlesection"}:
                    if isinstance(v, str):
                        encontrados.append(v)
                    elif isinstance(v, list):
                        encontrados.extend([x for x in v if isinstance(x, str)])
                else:
                    encontrados.extend(self._buscar_categorias_jsonld(v))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_categorias_jsonld(item))
        return encontrados

    def extraer_imagen(self, response):
        candidatos = []

        # 1. Metas principales
        for sel in [
            'meta[property="og:image"]::attr(content)',
            'meta[name="twitter:image"]::attr(content)',
            'meta[itemprop="image"]::attr(content)',
        ]:
            val = response.css(sel).get()
            if val:
                candidatos.append(val)

        # 2. Imágenes del producto y lazy-load
        for sel in [
            '.woocommerce-product-gallery__image a::attr(href)',
            '.woocommerce-product-gallery__image img::attr(src)',
            '.woocommerce-product-gallery__image img::attr(data-src)',
            '.woocommerce-product-gallery__image img::attr(data-large_image)',
            '.product img::attr(src)',
            '.product img::attr(data-src)',
            '.product img::attr(data-lazy-src)',
            '.product img::attr(data-large_image)',
            'img.wp-post-image::attr(src)',
            'img.wp-post-image::attr(data-src)',
            'img::attr(src)',
            'img::attr(data-src)',
            'img::attr(data-lazy-src)',
            'img::attr(data-original)',
        ]:
            candidatos.extend(response.css(sel).getall())

        # 3. Srcset
        for srcset in response.css('img::attr(srcset)').getall():
            partes = [p.strip() for p in srcset.split(',') if p.strip()]
            for parte in partes:
                url = parte.split(' ')[0].strip()
                if url:
                    candidatos.append(url)

        # 4. JSON-LD image
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue
            candidatos.extend(self._buscar_imagenes_jsonld(data))

        for img in candidatos:
            img = (img or "").strip()
            if not img or img.startswith("data:image"):
                continue
            img_l = img.lower()
            if any(x in img_l for x in ["logo", "icon", "favicon", "placeholder", "loader", "banner"]):
                continue
            if img.startswith("//"):
                img = f"https:{img}"
            return response.urljoin(img)

        return ""

    def _buscar_imagenes_jsonld(self, data):
        encontrados = []
        if isinstance(data, dict):
            for k, v in data.items():
                if k.lower() == "image":
                    if isinstance(v, str):
                        encontrados.append(v)
                    elif isinstance(v, list):
                        encontrados.extend([x for x in v if isinstance(x, str)])
                    elif isinstance(v, dict):
                        url = v.get("url")
                        if isinstance(url, str):
                            encontrados.append(url)
                else:
                    encontrados.extend(self._buscar_imagenes_jsonld(v))
        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_imagenes_jsonld(item))
        return encontrados

    def extraer_descripcion(self, response):
        """
        Extrae descripción/características en la estructura nueva de Computex.

        Computex ahora usa bloques distintos según el producto: algunos vienen con
        contenido de WooCommerce, otros con Elementor/constructores visuales y
        otros solo traen JSON-LD/meta. Por eso se prueban varias fuentes y se
        filtran textos de menú, precio, carrito y navegación.
        """
        bloques = []
        nombre_producto = self.limpiar_texto(
            response.css("h1::text").get()
            or response.css(".product_title::text").get()
            or response.css(".entry-title::text").get(default="")
        )

        # 1) Selectores directos: WooCommerce + Elementor + constructores comunes.
        selectores = [
            ".woocommerce-Tabs-panel--description *::text",
            "#tab-description *::text",
            ".woocommerce-product-details__short-description *::text",
            ".summary .woocommerce-product-details__short-description *::text",
            ".product .woocommerce-product-details__short-description *::text",
            ".entry-summary *::text",
            ".summary *::text",
            ".product-summary *::text",
            ".jet-woo-builder-single-excerpt *::text",
            ".jet-single-excerpt *::text",
            ".jet-woo-product-excerpt *::text",
            ".elementor-widget-woocommerce-product-short-description *::text",
            ".elementor-widget-woocommerce-product-content *::text",
            ".elementor-widget-theme-post-content *::text",
            ".elementor-widget-text-editor *::text",
            ".elementor-tab-content *::text",
            ".jet-listing-dynamic-field__content *::text",
            ".entry-content *::text",
            ".product-description *::text",
            ".description *::text",
        ]

        for sel in selectores:
            texto = self.unir_textos_descripcion(response.css(sel).getall(), nombre_producto=nombre_producto)
            if self.descripcion_util(texto) and not self.descripcion_es_solo_titulo(texto, nombre_producto):
                bloques.append(texto)

        # 2) JSON-LD: muchos sitios guardan description en Schema.org Product.
        for raw in response.css('script[type="application/ld+json"]::text').getall():
            try:
                data = json.loads(raw)
            except Exception:
                continue

            for desc in self._buscar_descripciones_jsonld(data):
                desc = self.limpiar_descripcion_final(desc, nombre_producto)
                if self.descripcion_util(desc) and not self.descripcion_es_solo_titulo(desc, nombre_producto):
                    bloques.append(desc)

        # 3) Fallback por cuerpo: buscar encabezados visibles y tomar lo que sigue.
        if not bloques:
            textos = [self.limpiar_texto(t) for t in response.css("body ::text").getall()]
            textos = [t for t in textos if self.texto_descripcion_valido(t)]

            encabezados_inicio = {
                "descripción", "descripcion", "descripción del producto", "descripcion del producto",
                "detalle", "detalles",
                "características", "caracteristicas", "características del producto",
                "caracteristicas del producto", "especificaciones",
                "especificaciones generales", "ficha técnica", "ficha tecnica",
                "información adicional", "informacion adicional",
            }
            encabezados_fin = [
                "también te puede gustar", "tambien te puede gustar",
                "productos relacionados", "valoraciones", "reseñas", "reviews",
                "volver a la lista", "categoría", "categoria", "compartir",
                "añadir al carrito", "agregar al carrito", "comprar",
            ]

            inicio = None
            fin = None

            for i, t in enumerate(textos):
                t_norm = self.normalizar_simple(t)

                if t_norm in encabezados_inicio:
                    inicio = i + 1
                    continue

                if inicio is not None and any(x in t_norm for x in encabezados_fin):
                    fin = i
                    break

            if inicio is not None:
                bloque = textos[inicio:fin] if fin is not None else textos[inicio:inicio + 60]
                texto = self.unir_textos_descripcion(bloque)
                if self.descripcion_util(texto) and not self.descripcion_es_solo_titulo(texto, nombre_producto):
                    bloques.append(texto)

        # 5) Fallback sin encabezado: en algunas fichas la descripción aparece
        # como texto suelto dentro del resumen del producto, después del título
        # y precio, sin clase clara ni encabezado "Descripción".
        if not bloques:
            texto = self.extraer_descripcion_por_proximidad(response, nombre_producto)
            if self.descripcion_util(texto) and not self.descripcion_es_solo_titulo(texto, nombre_producto):
                bloques.append(texto)

        # 5) Respaldo fuerte: algunos builders dejan HTML escapado dentro de
        # scripts/JSON. Lo decodificamos y buscamos fragmentos útiles.
        if not bloques:
            texto = self.extraer_descripcion_desde_scripts(response, nombre_producto)
            if self.descripcion_util(texto) and not self.descripcion_es_solo_titulo(texto, nombre_producto):
                bloques.append(texto)

        # 6) Meta description como ÚLTIMO recurso. En Computex varios productos
        # tienen el meta description igual al nombre, por eso no debe bloquear
        # los fallbacks anteriores.
        if not bloques:
            for sel in [
                'meta[name="description"]::attr(content)',
                'meta[property="og:description"]::attr(content)',
                'meta[name="twitter:description"]::attr(content)',
            ]:
                desc = self.limpiar_descripcion_final(response.css(sel).get(), nombre_producto)
                if self.descripcion_util(desc) and not self.descripcion_es_solo_titulo(desc, nombre_producto):
                    bloques.append(desc)
                    break

        if not bloques:
            return ""

        # Quita duplicados conservando orden.
        unicos = []
        vistos = set()
        for bloque in bloques:
            bloque = self.limpiar_descripcion_final(bloque, nombre_producto)
            key = self.normalizar_simple(bloque)
            if not key or key in vistos:
                continue
            vistos.add(key)
            unicos.append(bloque)

        descripcion = self.limpiar_descripcion_final(" ".join(unicos), nombre_producto)

        if not self.descripcion_util(descripcion):
            return ""

        return descripcion

    def unir_textos_descripcion(self, textos, nombre_producto=None):
        limpios = []
        nombre_norm = self.normalizar_simple(nombre_producto or "")

        for t in textos:
            t = self.limpiar_texto(t)
            if not t:
                continue

            t_norm = self.normalizar_simple(t)

            # Si usamos selectores amplios como .summary, el título y el precio
            # vienen mezclados con la descripción. Los quitamos acá.
            if nombre_norm and t_norm == nombre_norm:
                continue
            if self.es_precio_texto(t):
                continue

            if self.texto_descripcion_valido(t):
                limpios.append(t)

        if not limpios:
            return ""

        return self.limpiar_descripcion_final(" ".join(limpios), nombre_producto)

    def extraer_descripcion_por_proximidad(self, response, nombre_producto):
        """
        Captura descripciones que están visibles como texto suelto cerca del
        h1/precio, sin clases WooCommerce/Elementor específicas.
        """
        textos = [self.limpiar_texto(t) for t in response.css("body ::text").getall()]
        textos = [t for t in textos if t]
        if not textos:
            return ""

        nombre_norm = self.normalizar_simple(nombre_producto or "")
        inicio = 0

        if nombre_norm:
            for i, t in enumerate(textos):
                if self.normalizar_simple(t) == nombre_norm:
                    inicio = i + 1
                    break

        fin_keywords = [
            "añadir al carrito", "agregar al carrito", "comprar", "finalizar compra",
            "tambien te puede gustar", "también te puede gustar",
            "productos relacionados", "valoraciones", "resenas", "reseñas", "reviews",
            "sku", "categoria", "categoría", "compartir", "volver a la lista",
            "medios de pago", "formas de pago", "calcular envio", "calcular envío",
        ]

        candidatos = []
        for t in textos[inicio:inicio + 140]:
            t_norm = self.normalizar_simple(t)

            if nombre_norm and t_norm == nombre_norm:
                continue
            if self.es_precio_texto(t):
                continue

            # Si ya empezamos a tomar descripción y aparece un bloque de UI,
            # cortamos para no mezclar relacionados/footer.
            if any(k in t_norm for k in fin_keywords):
                if candidatos:
                    break
                continue

            if self.texto_descripcion_valido(t):
                candidatos.append(t)

            if len(" ".join(candidatos)) >= 1200:
                break

        texto = self.unir_textos_descripcion(candidatos, nombre_producto=nombre_producto)

        # Evita guardar basura si solo capturó una palabra suelta del resumen.
        if not self.descripcion_util(texto):
            return ""

        return texto

    def extraer_descripcion_desde_scripts(self, response, nombre_producto):
        """
        Respaldo para HTML/JSON escapado dentro de scripts de builders.
        No reemplaza a los selectores normales; solo corre si nada más funcionó.
        """
        candidatos = []
        nombre_norm = self.normalizar_simple(nombre_producto or "")

        for raw in response.css("script::text").getall():
            if not raw or len(raw) < 30:
                continue

            variantes = [raw]
            variantes.append(html.unescape(raw))
            try:
                variantes.append(bytes(raw, "utf-8").decode("unicode_escape"))
            except Exception:
                pass

            for contenido in variantes:
                contenido = html.unescape(contenido.replace("\\/", "/"))

                # Reducir ruido: si el script no menciona el producto ni contiene
                # términos típicos de ficha técnica, lo ignoramos.
                contenido_norm = self.normalizar_simple(contenido[:250000])
                terminos_tecnicos = (
                    r"\b(potencia|interfaz|conectividad|compatible|bluetooth|usb|aux|rms|"
                    r"salida|entrada|pantalla|certificacion|certificación|frecuencia|hz|khz|"
                    r"impedancia|ohm|sensibilidad|db|cono|polipropileno|suspension|suspensión|"
                    r"profundidad|montaje|parlante|parlantes|automovil|automóvil|vehiculo|vehículo|serie)\b"
                )
                if nombre_norm and nombre_norm not in contenido_norm and not re.search(terminos_tecnicos, contenido_norm):
                    continue

                # Separadores HTML comunes.
                limpio = re.sub(r"<br\s*/?>", "\n", contenido, flags=re.IGNORECASE)
                limpio = re.sub(r"</(?:p|li|div|h[1-6])>", "\n", limpio, flags=re.IGNORECASE)
                limpio = re.sub(r"<[^>]+>", " ", limpio)
                limpio = limpio.replace("\\n", "\n")
                limpio = limpio.replace("\\t", " ")
                limpio = re.sub(r"[{}\[\]_;]+", " ", limpio)

                # Si el builder guarda todo en un bloque largo, intentamos cortar
                # desde marcadores reales de ficha/descripción antes de partirlo.
                for bloque_largo in self.extraer_segmentos_descripcion_texto(limpio):
                    bloque_largo = self.limpiar_descripcion_final(bloque_largo, nombre_producto)
                    if bloque_largo and self.texto_descripcion_valido(bloque_largo):
                        candidatos.append(bloque_largo)

                partes = re.split(r"\n+|(?<=\.)\s+|(?<=:)\s+(?=[A-ZÁÉÍÓÚÑa-záéíóúñ])", limpio)
                for parte in partes:
                    parte = self.limpiar_texto(parte)
                    if not parte:
                        continue
                    if nombre_norm and self.normalizar_simple(parte) == nombre_norm:
                        continue
                    if self.es_precio_texto(parte):
                        continue
                    if self.texto_descripcion_valido(parte):
                        candidatos.append(parte)

        if not candidatos:
            return ""

        # Dar prioridad a líneas con formato de especificación o términos técnicos.
        tecnicos = []
        for c in candidatos:
            cn = self.normalizar_simple(c)
            if ":" in c or re.search(
                r"\b(potencia|interfaz|conectividad|compatible|bluetooth|usb|aux|rms|w|v|ohm|salida|entrada|pantalla|certificacion|certificación|frecuencia|hz|khz|impedancia|sensibilidad|db|cono|polipropileno|suspension|suspensión|profundidad|montaje|serie|parlantes?)\b",
                cn,
            ):
                tecnicos.append(c)

        elegidos = tecnicos or candidatos[:12]
        return self.unir_textos_descripcion(elegidos[:12], nombre_producto=nombre_producto)

    def es_precio_texto(self, texto):
        texto = self.limpiar_texto(texto)
        if not texto:
            return False

        texto_norm = self.normalizar_simple(texto)
        return bool(re.fullmatch(
            r"(?:₲|gs\.?|g\$)?\s*[0-9]{1,3}(?:[\.\s][0-9]{3})+\s*(?:₲|gs\.?|g\$)?|(?:₲|gs\.?|g\$)?\s*[0-9]+\s*(?:₲|gs\.?|g\$)?",
            texto_norm,
            flags=re.IGNORECASE,
        ))

    def texto_descripcion_valido(self, texto):
        texto = self.limpiar_texto(texto)
        if not texto:
            return False

        texto_norm = self.normalizar_simple(texto)

        # Textos de interfaz que suelen contaminar la descripción.
        basura_exacta = {
            "inicio", "home", "productos", "producto", "tienda", "carrito",
            "mi cuenta", "buscar", "search", "menu", "menú", "categorias",
            "categorías", "añadir al carrito", "agregar al carrito",
            "comprar", "leer más", "leer mas", "seleccionar opciones",
            "descripción", "descripcion", "especificaciones generales",
            "ver mas de cerca", "ver más de cerca", "opciones de envio", "opciones de envío",
        }
        if texto_norm in basura_exacta:
            return False

        basura_contiene = [
            "whatsapp", "facebook", "instagram", "copyright", "todos los derechos",
            "computex", "inicio /", "sku:", "categoría:", "categoria:",
            "añadir al carrito", "agregar al carrito", "finalizar compra",
            "opciones de envio", "opciones de envío", "transportadora depto central",
            "transportadora alto parana", "transportadora a todo el pais",
            "delivery caacupe", "del local gratis", "ver mas de cerca", "ver más de cerca",
        ]
        if any(x in texto_norm for x in basura_contiene):
            return False

        # Evitar precios sueltos o botones.
        if re.fullmatch(r"(?:₲|gs\.?|g\$)?\s*[0-9\.\s]+\s*(?:₲|gs\.?|g\$)?", texto_norm):
            return False

        return len(texto) >= 3

    def descripcion_util(self, texto):
        texto = self.limpiar_descripcion(texto)
        if not texto:
            return False

        texto_norm = self.normalizar_simple(texto)
        if texto_norm in {
            "descripción", "descripcion", "especificaciones", "especificaciones generales",
            "productos", "producto", "sin descripcion", "sin descripción",
        }:
            return False

        # Debe tener algo de contenido real. Permitimos descripciones cortas tipo
        # "Bluetooth / USB / Aux" si contienen separadores o datos técnicos.
        palabras = re.findall(r"[a-záéíóúñ0-9]+", texto_norm)
        if len(palabras) >= 4:
            return True

        if re.search(r"\b(?:usb|hdmi|bluetooth|wifi|rms|gb|tb|mah|hz|w|v|ohm|full\s*hd|4k)\b", texto_norm):
            return True

        return False

    def descripcion_es_solo_titulo(self, descripcion, nombre_producto):
        """Evita guardar como descripción el mismo nombre del producto.

        Computex a veces carga el meta description como el título del producto.
        Normalizamos símbolos como ×, comillas y pulgadas para detectar casos
        como: 4×4″190W vs 4x4"190W.
        """
        if not descripcion or not nombre_producto:
            return False

        desc_norm = self.normalizar_simple(descripcion)
        nombre_norm = self.normalizar_simple(nombre_producto)
        desc_key = self.clave_titulo(descripcion)
        nombre_key = self.clave_titulo(nombre_producto)

        if not desc_key or not nombre_key:
            return False

        if desc_key == nombre_key:
            return True

        # Casos comunes de meta description: "NOMBRE - Computex" o
        # "NOMBRE | Computex". Si al quitar la marca del sitio queda el título,
        # no es una descripción real.
        desc_sin_tienda = re.sub(r"\bcomputex\b", " ", desc_norm)
        desc_sin_tienda = re.sub(r"[-|–—]+", " ", desc_sin_tienda)
        desc_sin_tienda = re.sub(r"\s+", " ", desc_sin_tienda).strip()
        if self.clave_titulo(desc_sin_tienda) == nombre_key:
            return True

        # Si una descripción es casi idéntica al título y no contiene señales
        # técnicas/descriptivas adicionales, la descartamos.
        if desc_key.startswith(nombre_key) or nombre_key.startswith(desc_key):
            extra = desc_key.replace(nombre_key, "").strip()
            if len(extra) <= 12:
                return True

        return False

    def clave_titulo(self, texto):
        """Clave agresiva para comparar títulos equivalentes con símbolos distintos."""
        texto = self.normalizar_simple(texto)
        texto = texto.replace("×", "x")
        texto = texto.replace("✕", "x")
        texto = texto.replace("″", "")
        texto = texto.replace("''", "")
        texto = texto.replace('"', "")
        texto = texto.replace("’", "")
        texto = texto.replace("'", "")
        texto = re.sub(r"[^a-z0-9]+", " ", texto)
        texto = re.sub(r"\s+", " ", texto).strip()
        return texto

    def extraer_segmentos_descripcion_texto(self, texto):
        """Busca segmentos útiles dentro de HTML/JS ya decodificado."""
        texto = html.unescape(texto or "")
        texto = texto.replace("\\n", "\n").replace("\\r", "\n").replace("\\t", " ")
        texto = re.sub(r"<br\s*/?>", "\n", texto, flags=re.IGNORECASE)
        texto = re.sub(r"</(?:p|li|div|h[1-6])>", "\n", texto, flags=re.IGNORECASE)
        texto = re.sub(r"<[^>]+>", " ", texto)
        texto = re.sub(r"\s+", " ", texto).strip()

        if not texto:
            return []

        marcadores = [
            r"descripci[oó]n\s+del\s+producto",
            r"caracter[ií]sticas\s+t[eé]cnicas",
            r"caracter[ií]sticas\s+del\s+producto",
            r"especificaciones\s+t[eé]cnicas",
            r"ficha\s+t[eé]cnica",
            r"potencia\s+m[aá]xima",
            r"potencia\s+rms",
            r"respuesta\s+de\s+frecuencia",
            r"serie\s*:",
        ]
        finales = [
            r"productos\s+relacionados",
            r"tambi[eé]n\s+te\s+puede\s+gustar",
            r"valoraciones",
            r"reseñas",
            r"reviews",
            r"añadir\s+al\s+carrito",
            r"agregar\s+al\s+carrito",
        ]

        segmentos = []
        for marcador in marcadores:
            m = re.search(marcador, texto, flags=re.IGNORECASE)
            if not m:
                continue
            start = m.start()
            end = min(len(texto), start + 1800)
            pedazo = texto[start:end]
            for fin in finales:
                fm = re.search(fin, pedazo, flags=re.IGNORECASE)
                if fm and fm.start() > 80:
                    pedazo = pedazo[:fm.start()]
                    break
            segmentos.append(pedazo)

        return segmentos

    def _buscar_descripciones_jsonld(self, data):
        encontrados = []

        if isinstance(data, dict):
            for k, v in data.items():
                k_norm = str(k).lower()
                if k_norm == "description":
                    if isinstance(v, str):
                        encontrados.append(v)
                    elif isinstance(v, list):
                        encontrados.extend([x for x in v if isinstance(x, str)])
                else:
                    encontrados.extend(self._buscar_descripciones_jsonld(v))

        elif isinstance(data, list):
            for item in data:
                encontrados.extend(self._buscar_descripciones_jsonld(item))

        return encontrados

    def normalizar_simple(self, texto):
        texto = self.limpiar_texto(texto).lower()
        reemplazos = {
            "á": "a", "é": "e", "í": "i", "ó": "o", "ú": "u", "ñ": "n",
            "×": "x", "✕": "x", "″": '"', "“": '"', "”": '"',
            "–": "-", "—": "-", "º": "",
        }
        for a, b in reemplazos.items():
            texto = texto.replace(a, b)
        texto = re.sub(r"\s+", " ", texto).strip()
        return texto

    def parse_precio(self, texto):
        """
        Computex puede mostrar precios como:
        - ₲ 145.095
        - Gs. 145.095
        - Gs 145.095

        La versión anterior solo aceptaba el símbolo ₲, por eso el spider
        entraba a los productos pero no hacía yield cuando el sitio mostraba Gs.
        """
        if not texto:
            return None

        texto = self.limpiar_texto(texto)

        patrones = [
            r'(?:₲|Gs\.?|G\$)\s*([0-9]{1,3}(?:[\.\s][0-9]{3})+|[0-9]+)',
            r'([0-9]{1,3}(?:[\.\s][0-9]{3})+|[0-9]+)\s*(?:₲|Gs\.?|G\$)',
        ]

        for patron in patrones:
            for match in re.finditer(patron, texto, flags=re.IGNORECASE):
                precio = self._precio_a_int(match.group(1))
                if precio is not None and 1000 <= precio <= 500_000_000:
                    return precio

        return None

    def _precio_a_int(self, valor):
        if valor is None:
            return None

        valor = str(valor).strip()
        valor = re.sub(r'[^0-9,\.\s]', '', valor)
        valor = re.sub(r'\s+', '', valor)

        if not valor:
            return None

        # Si algún día aparece decimal, descartamos la parte decimal.
        if ',' in valor:
            partes = valor.split(',')
            if len(partes[-1]) == 2:
                valor = ''.join(partes[:-1])
            else:
                valor = valor.replace(',', '')

        valor = valor.replace('.', '')
        valor = re.sub(r'\D', '', valor)

        if not valor:
            return None

        try:
            return int(valor)
        except ValueError:
            return None
