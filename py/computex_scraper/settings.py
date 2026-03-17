BOT_NAME = "computex_scraper"

SPIDER_MODULES = ["computex_scraper.spiders"]
NEWSPIDER_MODULE = "computex_scraper.spiders"

ROBOTSTXT_OBEY = False

ITEM_PIPELINES = {
    "computex_scraper.pipelines.MySQLPipeline": 300,
}

DOWNLOAD_DELAY = 1.0
REQUEST_FINGERPRINTER_IMPLEMENTATION = "2.7"
TWISTED_REACTOR = "twisted.internet.asyncioreactor.AsyncioSelectorReactor"
FEED_EXPORT_ENCODING = "utf-8"

DEFAULT_REQUEST_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36"
}