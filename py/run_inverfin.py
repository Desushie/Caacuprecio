import os
import sys

sys.path.append(r"C:\xampp\htdocs\Caacuprecio\py")
os.environ.setdefault("SCRAPY_SETTINGS_MODULE", "scraper.settings")

from scraper.spiders.inverfin_productos import InverfinProductosSpider
from scraper.utils.runner import run_spiders


def main():
    run_spiders(InverfinProductosSpider)


if __name__ == "__main__":
    main()
