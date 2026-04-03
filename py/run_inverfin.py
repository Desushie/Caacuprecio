import os
import sys

sys.path.append(r"C:\xampp\htdocs\Caacuprecio\py")
os.environ.setdefault("SCRAPY_SETTINGS_MODULE", "scraper.settings")

from scrapy.crawler import CrawlerProcess
from scrapy.utils.project import get_project_settings


from scraper.spiders.inverfin_productos import InverfinProductosSpider



def main():
    settings = get_project_settings()
    process = CrawlerProcess(settings)

    # rápidos primero

    # lento al final
    process.crawl(InverfinProductosSpider)

    process.start()


if __name__ == "__main__":
    main()