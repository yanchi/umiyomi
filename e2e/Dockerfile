# ブラウザ本体と依存ライブラリ入りの公式イメージ。バージョンは e2e/package.json の @playwright/test と必ず揃える
FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /e2e

COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund

COPY . .

CMD ["npx", "playwright", "test"]
