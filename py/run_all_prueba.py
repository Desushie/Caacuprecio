import os
import sys

sys.path.append(r"C:\xampp2\htdocs\Caacuprecio\py")
os.environ.setdefault("SCRAPY_SETTINGS_MODULE", "scraper.settings")

from scrapy.crawler import CrawlerProcess
from scrapy.utils.project import get_project_settings

# SOLO LOS NUEVOS
from scraper.spiders.gonzalito_productos import GonzalitoProductosSpider
from scraper.spiders.alex_productos import AlexProductosSpider
from scraper.spiders.chacomer_productos import ChacomerProductosSpider


def main():
    settings = get_project_settings()
    process = CrawlerProcess(settings)

    process.crawl(GonzalitoProductosSpider)
    process.crawl(AlexProductosSpider)
    process.crawl(ChacomerProductosSpider)

    process.start()


if __name__ == "__main__":
    main()