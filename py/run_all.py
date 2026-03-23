from scrapy.crawler import CrawlerProcess
from scrapy.utils.project import get_project_settings

from scraper.spiders.computex_productos import ComputexProductosSpider
from scraper.spiders.ch_productos import CHProductosSpider
from scraper.spiders.fulloffice_productos import FullOfficeProductosSpider
from scraper.spiders.bristol_productos import BristolProductosSpider
from scraper.spiders.inverfin_productos import InverfinProductosSpider


def main():
    settings = get_project_settings()

    process = CrawlerProcess(settings)

    process.crawl(ComputexProductosSpider)
    process.crawl(CHProductosSpider)
    process.crawl(FullOfficeProductosSpider)
    process.crawl(BristolProductosSpider)
    process.crawl(InverfinProductosSpider)

    process.start()


if __name__ == "__main__":
    main()