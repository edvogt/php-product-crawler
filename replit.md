# Product Discovery & Extraction Crawler

## Overview
A PHP 8+ product crawler with SQLite caching that discovers products via sitemaps and category pages, scores pages based on content quality, matches model numbers, and exports to semicolon-delimited CSV.

## Project Architecture
- **crawler.php**: Single-file implementation (~600 LOC) with OOP classes:
  - Config: Configuration management
  - Log: Console logging
  - Db: SQLite3 discovery cache with 24-hour expiry
  - Http: HTTP client with gzip, redirects, proper User-Agent
  - SiteDiscovery: Dual discovery via sitemaps + category crawling
  - ModelMatcher: Model pattern matching against provided list
  - PageScorer: Intelligent scoring (≥20 threshold) based on title, specs, images, JSON-LD, etc.
  - DataExtractor: Description, short description, and image URL extraction
  - App: Main orchestration logic

- **run_crawler.sh**: Bash wrapper with environment variable support

## Usage
```bash
# Direct usage
php crawler.php --base=https://example.com --models=models.txt --out=products.csv [--force]

# Using bash wrapper with environment variables
BASE_URL=https://example.com MODELS_FILE=models.txt OUT_FILE=products.csv ./run_crawler.sh

# Force refresh (bypass cache)
php crawler.php --base=https://example.com --models=models.txt --out=products.csv --force
```

## Features
- Dual discovery: XML sitemaps + category page crawling
- SQLite cache reused if < 24 hours old
- Page scoring with 20+ point threshold (title, specs, images, physical specs, docs, JSON-LD Product, canonical, H1)
- Advanced image extraction: src, data-src, srcset, picture elements
- Icon/placeholder filtering, absolute URLs, max 10 images per product
- Model matching with flexible pattern recognition
- CSV output with semicolon delimiters, proper escaping (&#59;)
- Respectful crawling: delays, gzip, redirects, User-Agent

## Recent Changes
- 2025-10-01: Initial implementation with corrected cleanText() regex pattern
