FROM node:22-bookworm-slim

ARG UID=1000
ARG GID=1000

RUN groupmod -g "${GID}" node \
    && usermod -u "${UID}" -g "${GID}" node

WORKDIR /var/www/html

USER node

CMD ["sleep", "infinity"]
