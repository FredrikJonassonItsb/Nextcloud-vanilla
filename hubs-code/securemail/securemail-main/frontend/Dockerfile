# ---------------------------------------------------------
# Use the official nginx Alpine-based image as the base
# ---------------------------------------------------------
FROM nginx:alpine

ARG USER_GID
ARG USER_UID
ARG USER_NAME
ARG DEBIAN_FRONTEND
ARG SECUREMAIL_FRONTEND_DEFAULTS_DIR
ARG SECUREMAIL_FRONTEND_HTTP_PORT
ARG SECUREMAIL_FRONTEND_HTTPS_PORT
ARG SECUREMAIL_FRONTEND_HTML_DIR
ARG NGINX_DEFAULTS
ARG NGINX_CONF

# ---------------------------------------------------------
# Install packages needed for envsubst, health checks, etc.
# ---------------------------------------------------------
RUN apk add --no-cache \
        curl \
        nano \
        wget \
        bash \
        nginx \
        sudo \
        openssl \
        npm \
        gettext

# ---------------------------------------------------------
# Create needed directories
# ---------------------------------------------------------
# RUN mkdir -p /usr/share/nginx/html \
#         && mkdir -p /opt/frontend 
RUN mkdir -p "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}"

# ---------------------------------------------------------
# Copy the securemail web files into the container
# ---------------------------------------------------------
COPY app "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}/app"

# ---------------------------------------------------------
# Install npm dependencies (both production and dev for build)
# ---------------------------------------------------------
RUN npm install --global pnpm@10.17.1
RUN cd "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}/app" && \
    pnpm install --frozen-lockfile && \
    pnpm list

# ---------------------------------------------------------
# Copy your custom entrypoint script and nginx configuration
# ---------------------------------------------------------
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY template_processor.sh /usr/local/bin/template_processor.sh
COPY nginx.conf "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}/nginx.conf"

# ---------------------------------------------------------
# Set working directory
# ---------------------------------------------------------
WORKDIR "${SECUREMAIL_FRONTEND_DEFAULTS_DIR}/app"

# ---------------------------------------------------------
# Set ownership, permissions, and default config directory
# ---------------------------------------------------------
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/template_processor.sh \
        && cp -a /etc/nginx "${NGINX_DEFAULTS}"

# ---------------------------------------------------------
# Expose ports for HTTP and HTTPS
# ---------------------------------------------------------
EXPOSE ${SECUREMAIL_FRONTEND_HTTP_PORT} ${SECUREMAIL_FRONTEND_HTTPS_PORT}

# ---------------------------------------------------------
# Specify the container entrypoint
# ---------------------------------------------------------
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ---------------------------------------------------------
# Run nginx in the foreground as the default command
# ---------------------------------------------------------
CMD ["nginx", "-g", "daemon off;"]
