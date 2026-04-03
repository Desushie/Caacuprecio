import json
import re
from urllib.parse import urlencode, urlparse, parse_qs

import scrapy
from scraper.items import ProductoItem
from scraper.utils.brands import extract_brand
from scraper.utils.categories import extract_category


class AlexProductosSpider(scrapy.Spider):
    name = "alex_productos"
    store_name = "Alex"
    allowed_domains = ["alex.com.py", "www.alex.com.py"]

    start_urls = [
        "https://www.alex.com.py/categoria/97/motocicletas?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/1/celulares-y-accesorios?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/7/televisores-y-equipos-de-audio?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/47/electrodomesticos?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/67/informatica?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/4/camas-y-colchones?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/9/cuidado-personal-y-salud?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/75/climatizacion?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/30/muebles?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/16/deportes?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/45/nautica?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/78/herramientas-y-jardin?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/92/linea-profesional?marcas=&categorias=&categorias_top=",
        "https://www.alex.com.py/categoria/110/automotores?marcas=&categorias=&categorias_top=",
    ]

    custom_settings = {
        "DOWNLOAD_DELAY": 0.25,
        "DUPEFILTER_DEBUG": False,
    }

    api_url = "https://www.alex.com.py/catalogo/get-productos"

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.vistos_urls = set()

    async def start(self):
        for url in self.start_urls:
            yield scrapy.Request(url, callback=self.parse, headers=self.default_headers())

    def start_requests(self):
        for url in self.start_urls:
            yield scrapy.Request(url, callback=self.parse, headers=self.default_headers())

    def parse(self, response):
        categoria_id = self.extraer_categoria_id(response)
        categoria_origen = self.extraer_categoria_origen(response)

        parsed = urlparse(response.url)
        q = parse_qs(parsed.query)

        marcas = (q.get("marcas", [""])[0] or "").strip()
        categorias = (q.get("categorias", [""])[0] or "").strip()
        categorias_top = (q.get("categorias_top", [""])[0] or "").strip()

        if not categoria_id:
            self.logger.warning("No se pudo detectar categoria_id para %s", response.url)
            return

        attempts = self.build_api_attempts(
            categoria_id=categoria_id,
            marcas=marcas,
            categorias=categorias,
            categorias_top=categorias_top,
            page=1,
        )

        if not attempts:
            return

        params = attempts[0]
        url = f"{self.api_url}?{urlencode(params, doseq=True)}"

        yield scrapy.Request(
            url,
            callback=self.parse_api,
            headers=self.api_headers(response),
            meta={
                "categoria_id": categoria_id,
                "categoria_origen": categoria_origen,
                "marcas": marcas,
                "categorias": categorias,
                "categorias_top": categorias_top,
                "api_attempts": attempts,
                "api_attempt_idx": 0,
                "page": 1,
            },
            dont_filter=True,
        )

    def parse_api(self, response):
        categoria_origen = response.meta.get("categoria_origen", "")
        attempts = response.meta.get("api_attempts", [])
        idx = response.meta.get("api_attempt_idx", 0)
        current_page = response.meta.get("page", 1)

        data = self.safe_json(response)
        items = self.extract_api_items(data)

        if not items:
            if idx + 1 < len(attempts):
                params = attempts[idx + 1].copy()
                params["page"] = current_page
                next_url = f"{self.api_url}?{urlencode(params, doseq=True)}"
                yield scrapy.Request(
                    next_url,
                    callback=self.parse_api,
                    headers=self.api_headers(response),
                    meta={**response.meta, "api_attempt_idx": idx + 1},
                    dont_filter=True,
                )
            else:
                self.logger.debug(
                    "Sin items en categoria=%s page=%s intento=%s",
                    response.meta.get("categoria_id"),
                    current_page,
                    idx,
                )
            return

        self.logger.info(
            "Alex categoria=%s page=%s items_api=%s",
            response.meta.get("categoria_id"),
            current_page,
            len(items),
        )

        for item in items:
            href = self.get_first(item, ["url_ver", "url", "link", "permalink"])
            if not href:
                continue

            href = response.urljoin(str(href).split("?")[0].rstrip("/"))
            if "/producto/" not in href:
                continue

            if href in self.vistos_urls:
                continue
            self.vistos_urls.add(href)

            prelim = {
                "nombre": self.limpiar_texto(self.get_first(item, ["nombre", "name", "titulo"])),
                "precio": self.parse_num(
                    self.get_first(item, ["getPrecio", "precio", "price", "precio_contado"])
                ),
                "imagen": self.get_first(
                    item,
                    ["primera_imagen_thumb", "imagen", "image", "foto", "foto_principal"]
                ),
                "marca": self.extract_brand_api(item),
                "categoria": categoria_origen,
                "stock": self.extract_stock_api(item),
                "descripcion": "",
            }
            yield response.follow(
                href,
                callback=self.parse_producto,
                meta={
                    "categoria_origen": categoria_origen,
                    "prelim": prelim,
                },
                headers=self.default_headers(),
            )

        if self.has_more_pages(data, current_page, len(items)):
            params = attempts[idx].copy()
            params["page"] = current_page + 1
            next_url = f"{self.api_url}?{urlencode(params, doseq=True)}"
            yield scrapy.Request(
                next_url,
                callback=self.parse_api,
                headers=self.api_headers(response),
                meta={**response.meta, "page": current_page + 1},
                dont_filter=True,
            )

    def parse_producto(self, response):
        prelim = response.meta.get("prelim", {}) or {}
        body_text = self.limpiar_texto(
            " ".join(t.strip() for t in response.css("body ::text").getall() if t.strip())
        )

        nombre = self.limpiar_texto(
            response.css("h1.title-ficha::text").get()
            or response.css("h1::text").get()
            or prelim.get("nombre")
            or response.css("title::text").get(default="")
        )
        nombre = re.sub(r"\s*-\s*Alex\s*S\.A\.?(\s*\|.*)?$", "", nombre, flags=re.I).strip()

        if not nombre:
            return

        precio_contado = self.extraer_precio_contado(response, body_text)
        if precio_contado is None:
            precio_contado = prelim.get("precio")
        if precio_contado is None:
            return

        imagen = (
            self.normalizar_imagen(prelim.get("imagen"), response)
            or self.extraer_imagen(response)
            or ""
        )

        descripcion = ""

        marca = self.extraer_marca(response, nombre, descripcion) or prelim.get("marca") or "Genérico"

        categoria_original = (
            prelim.get("categoria")
            or response.meta.get("categoria_origen")
            or ""
        )

        categoria = extract_category(
            nombre=nombre,
            categoria_original=categoria_original,
            marca=marca,
        )

        stock = self.extraer_stock(response)
        if stock is None:
            stock = prelim.get("stock")

        item = ProductoItem()
        item["nombre"] = nombre
        item["precio"] = precio_contado
        item["url"] = response.url.split("?")[0].rstrip("/")
        item["categoria"] = categoria
        item["tienda"] = self.store_name
        item["stock"] = stock
        item["imagen"] = imagen
        item["marca"] = marca
        item["descripcion"] = descripcion
        yield item

    def build_api_attempts(self, categoria_id, marcas="", categorias="", categorias_top="", page=1):
        base_common = {
            "marcas": marcas,
            "categorias": categorias,
            "categorias_top": categorias_top,
            "ordenar_por": 0,
            "limit": 24,
            "page": page,
        }

        attempts = [
            {**base_common, "categoria": categoria_id},
            {**base_common, "categoria_id": categoria_id},
            {**base_common, "id_categoria": categoria_id},
            {
                **base_common,
                "categorias": categoria_id,
                "marcas": marcas,
                "categorias_top": categorias_top,
            },
        ]

        unique = []
        seen = set()
        for d in attempts:
            key = tuple(sorted(d.items()))
            if key not in seen:
                seen.add(key)
                unique.append(d)
        return unique

    def extract_api_items(self, data):
        if not isinstance(data, dict):
            return []

        pag = data.get("paginacion")
        if isinstance(pag, dict) and isinstance(pag.get("data"), list):
            return pag["data"]

        for key in ["data", "productos", "items", "results"]:
            if isinstance(data.get(key), list):
                return data[key]
            if isinstance(data.get(key), dict) and isinstance(data[key].get("data"), list):
                return data[key]["data"]

        return []

    def has_more_pages(self, data, current_page, item_count):
        if isinstance(data, dict):
            pag = data.get("paginacion")
            if isinstance(pag, dict):
                cp = pag.get("current_page") or current_page
                lp = pag.get("last_page")
                if isinstance(lp, int) and isinstance(cp, int):
                    return cp < lp

                next_page_url = pag.get("next_page_url")
                if next_page_url:
                    return True

        return item_count >= 50

    def default_headers(self):
        return {
            "User-Agent": (
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/123.0.0.0 Safari/537.36"
            ),
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "es-PY,es;q=0.9,en;q=0.8",
        }

    def api_headers(self, response):
        return {
            "User-Agent": self.default_headers()["User-Agent"],
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "es-PY,es;q=0.9,en;q=0.8",
            "Referer": "https://www.alex.com.py/",
            "X-Requested-With": "XMLHttpRequest",
        }

    def extraer_categoria_id(self, response):
        m = re.search(r"/categoria/(\d+)/", response.url)
        if m:
            return m.group(1)

        m = re.search(r'categoria\s*:\s*\{\s*"id"\s*:\s*(\d+)', response.text)
        if m:
            return m.group(1)

        return ""

    def extraer_categoria_origen(self, response):
        m = re.search(r"/categoria/(\d+)/", response.url)
        if not m:
            return ""

        categoria_id = m.group(1)

        MAPA_CATEGORIAS = {
            "1": "Celulares y Smartphones",
            "4": "Hogar",
            "7": "TV y Video",
            "9": "Salud y Belleza",
            "16": "Deportes",
            "30": "Hogar",
            "45": "Deportes",
            "47": "Electrodomésticos",
            "67": "Informática",
            "75": "Climatización",
            "78": "Herramientas",
            "92": "Herramientas",
            "97": "Motocicletas",
            "110": "Motocicletas",
        }

        return MAPA_CATEGORIAS.get(categoria_id, "")

    def limpiar_texto(self, texto):
        if not texto:
            return ""
        texto = re.sub(r"<[^>]+>", " ", str(texto))
        texto = re.sub(r"\s+", " ", texto)
        return texto.strip(" -\n\t\r")

    def extract_brand_api(self, item):
        # 1. Intentar desde campos directos de la API
        for key in ["marca", "brand", "nombre_marca", "fabricante"]:
            val = item.get(key)
            if val:
                return self.limpiar_texto(val).upper()

        # 2. Obtener nombre
        nombre = item.get("nombre") or item.get("name") or item.get("titulo")
        if not nombre:
            return ""

        nombre = self.limpiar_texto(nombre)

        # 3. Intentar con tu sistema de marcas
        marca = extract_brand(nombre)
        if marca:
            return marca.upper()

        # 4. Evitar palabras genéricas
        GENERICAS = {
            "parlante", "tv", "auricular", "autoradio", "freidora",
            "licuadora", "anafe", "termo", "heladera", "horno",
            "hidrolavadora", "cocina", "microondas", "ventilador",
            "aire", "acondicionado", "notebook", "tablet"
        }

        # 5. Buscar primera palabra válida
        palabras = nombre.split()
        for palabra in palabras[:3]:  # revisa primeras 3 palabras
            limpia = self.limpiar_texto(palabra).lower()
            if not limpia or limpia in GENERICAS:
                continue
            if len(limpia) <= 2:
                continue
            return limpia.upper()

        return ""

    def extract_stock_api(self, item):
        for key in ["stock", "tiene_stock", "available", "disponible"]:
            if key not in item:
                continue

            val = item.get(key)

            if isinstance(val, bool):
                return 1 if val else 0

            if isinstance(val, (int, float)):
                return 1 if val > 0 else 0

            if isinstance(val, str):
                v = val.strip().lower()
                if v in {"1", "true", "si", "sí", "disponible", "en stock"}:
                    return 1
                if v in {"0", "false", "no", "sin stock", "agotado"}:
                    return 0

        return None

    def extraer_precio_contado(self, response, body_text):
        for sel in [
            ".compra-contado-block .contado-precio::text",
            ".contado-precio::text",
            "[data-price-cash]::attr(data-price-cash)",
            "[data-precio-contado]::attr(data-precio-contado)",
            'meta[property="product:price:amount"]::attr(content)',
            'meta[itemprop="price"]::attr(content)',
        ]:
            val = response.css(sel).get()
            if val:
                parsed = self.parse_num(val)
                if parsed is not None:
                    return parsed

        for raw in response.css("script::text").getall():
            m = re.search(r'item_price\s*[:=]\s*["\']?([\d\.]+)', raw, re.I)
            if m:
                parsed = self.parse_num(m.group(1))
                if parsed is not None:
                    return parsed

        nums = []
        for txt in response.css(".product-price ::text, .contado-precio ::text").getall():
            parsed = self.parse_num(txt)
            if parsed is not None:
                nums.append(parsed)
        if nums:
            return max(nums)

        nums = []
        for match in re.findall(r"Gs\.?\s*([\d\.]+)", body_text, re.I):
            parsed = self.parse_num(match)
            if parsed is not None:
                nums.append(parsed)

        return max(nums) if nums else None

    def extraer_imagen(self, response):
        candidatos = []

        for sel in [
            ".img-single::attr(data-zoom)",
            ".img-single::attr(data-src)",
            ".img-single::attr(src)",
            "img.img-producto::attr(data-zoom)",
            "img.img-producto::attr(data-src)",
            "img.img-producto::attr(src)",
            'img[src*="/storage/"]::attr(src)',
            'img[src*="producto"]::attr(src)',
            'img[src*="sku"]::attr(src)',
        ]:
            candidatos.extend(response.css(sel).getall())

        for raw in response.css("script::text").getall():
            for pattern in [
                r'primera_imagen_thumb\s*[:=]\s*[\'"]([^\'"]+)',
                r'primera_imagen\s*[:=]\s*[\'"]([^\'"]+)',
                r'"primera_imagen_thumb"\s*:\s*"([^"]+)"',
                r'"primera_imagen"\s*:\s*"([^"]+)"',
                r'"imagen"\s*:\s*"([^"]+/storage/[^"]+)"',
            ]:
                candidatos.extend(re.findall(pattern, raw, re.I))

        for val in candidatos:
            img = self.normalizar_imagen(val, response)
            if img and not self.es_placeholder_imagen(img):
                return img

        for sel in [
            'meta[property="og:image"]::attr(content)',
            "img.img-ficha::attr(src)",
        ]:
            val = response.css(sel).get()
            img = self.normalizar_imagen(val, response)
            if img:
                return img

        return ""

    def extraer_categoria_producto(self, response, nombre):
        categoria_origen = self.limpiar_texto(response.meta.get("categoria_origen", ""))
        candidatos = []

        breadcrumbs = [
            self.limpiar_texto(t)
            for t in response.css('.breadcrumb a::text, [class*="breadcrumb"] a::text').getall()
            if self.limpiar_texto(t)
        ]
        candidatos.extend(breadcrumbs)

        m = re.search(r'categoria\s*:\s*\{[^\}]*"nombre"\s*:\s*"([^"]+)"', response.text, re.I)
        if m:
            candidatos.append(self.limpiar_texto(m.group(1)))

        if categoria_origen:
            candidatos.append(categoria_origen)

        cat_nombre = self.limpiar_texto(extract_category(nombre))
        if cat_nombre:
            candidatos.append(cat_nombre)

        ignorar = {
            "inicio",
            "home",
            "alex",
            "alex s.a.",
            "alex s.a",
            "producto",
            "productos",
            "tienda",
            "shop",
            "sin categoría",
            "sin categoria",
            "uncategorized",
        }

        for cat in candidatos:
            cat = self.limpiar_texto(cat)
            if not cat:
                continue
            if "|" in cat:
                cat = self.limpiar_texto(cat.split("|")[0])
            if cat.lower() in ignorar:
                continue
            if cat.lower() == nombre.lower():
                continue
            if len(cat) < 3:
                continue
            return cat

        return "Otros"

    def extraer_marca(self, response, nombre, descripcion):
        for raw in response.css("script::text").getall():
            m = re.search(r'"marca"\s*:\s*"([^"]+)"', raw, re.I)
            if m:
                return self.limpiar_texto(m.group(1)).upper()

        marca = extract_brand(nombre)
        if marca:
            return marca.upper()

        marca = extract_brand(descripcion)
        if marca:
            return marca.upper()

        return ""

    def extraer_stock(self, response):
        for sel in [
            "#cantidad-input::attr(max)",
            ".cantidad-input::attr(max)",
            'input[type="number"]::attr(max)',
        ]:
            val = response.css(sel).get()
            if val:
                try:
                    return 1 if int(val) > 0 else 0
                except Exception:
                    pass

        texto = response.text.lower()

        if any(x in texto for x in ["sin stock", "agotado", "no disponible"]):
            return 0

        if any(x in texto for x in ["agregar al carrito", "añadir al carrito", "comprá al contado", "comprar al contado"]):
            return 1

        return 1

    def safe_json(self, response):
        try:
            return json.loads(response.text)
        except Exception:
            return {}

    def parse_num(self, val):
        if val is None:
            return None
        txt = str(val)
        txt = txt.replace("Gs.", "").replace("Gs", "")
        txt = re.sub(r"[^\d]", "", txt)
        return int(txt) if txt else None

    def get_first(self, data, keys):
        for key in keys:
            if isinstance(data, dict) and data.get(key) not in (None, ""):
                return data.get(key)
        return None

    def es_placeholder_imagen(self, url):
        if not url:
            return True
        u = str(url).lower()
        return "social_seo" in u or "no-image" in u or "placeholder" in u

    def normalizar_imagen(self, url, response=None):
        if not url:
            return ""
        url = str(url).strip().replace("\\/", "/")
        if response is not None:
            url = response.urljoin(url)
        elif url.startswith("/"):
            url = "https://www.alex.com.py" + url
        return url

    def dedup_text_preserve_order(self, values):
        seen = set()
        result = []
        for value in values:
            key = self.limpiar_texto(value).lower()
            if not key or key in seen:
                continue
            seen.add(key)
            result.append(self.limpiar_texto(value))
        return result