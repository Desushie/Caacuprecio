BOT_NAME = "scraper"

SPIDER_MODULES = ["scraper.spiders"]
NEWSPIDER_MODULE = "scraper.spiders"

ROBOTSTXT_OBEY = False

ITEM_PIPELINES = {
    "scraper.pipelines.MySQLPipeline": 300,
}

DOWNLOAD_DELAY = 2
RANDOMIZE_DOWNLOAD_DELAY = True

CONCURRENT_REQUESTS = 2
CONCURRENT_REQUESTS_PER_DOMAIN = 2

RETRY_TIMES = 5
REQUEST_FINGERPRINTER_IMPLEMENTATION = "2.7"
TWISTED_REACTOR = "twisted.internet.asyncioreactor.AsyncioSelectorReactor"
FEED_EXPORT_ENCODING = "utf-8"

# En DEBUG Scrapy vuelca cada item scrapeado y el log de una corrida llega a
# decenas de MB, más de lo que admite scraper_jobs.output (max_allowed_packet).
LOG_LEVEL = "INFO"

# Cotización usada para pasar a guaraníes los productos que una tienda publica
# en dólares (p. ej. motos Benelli/Morbidelli de Inverfin). Actualizar a mano.
USD_PYG_RATE = 5715

DEFAULT_REQUEST_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
}