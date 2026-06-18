# Use Node.js LTS version
FROM node:20-slim

ARG LISTEN_PORT

# --------------------------------------------------------------------
# Update and Install Base Packages and Specific Packages
RUN apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install --no-install-recommends -y \
        lsb-release \
        gnupg \
        openssh-client \
        iputils-ping \
        tree \
        telnet \
        coreutils \
        net-tools \
        less \
        ca-certificates \
        unzip \
        nano \
        curl \
        htop \
        wget \
        sudo \
        jq \
        expect \
        tcpflow \
        iproute2 \
        netcat-openbsd \
        python3 \
        python3-pip \
        build-essential \
        gettext-base \
        socat && \
        rm -rf /var/lib/apt/lists/*

# --------------------------------------------------------------------
# Create dirs
RUN mkdir -p /opt/templates

# --------------------------------------------------------------------
# Set workdir
WORKDIR /opt/backend

# --------------------------------------------------------------------
# Copy files
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# Copy app source
COPY app/server.js /opt/templates/server.js
COPY app/pnpm-lock.yaml /opt/templates/pnpm-lock.yaml
COPY app/package.json /opt/templates/package.json
COPY app/otpStore.js /opt/templates/otpStore.js
COPY app/ticketStore.js /opt/templates/ticketStore.js
COPY app/sdkmcClient.js /opt/templates/sdkmcClient.js
COPY app/routes/ssoAuthRoutes.js /opt/templates/routes/ssoAuthRoutes.js
COPY app/*.sh /opt/templates/

# --------------------------------------------------------------------
# Install dependencies
RUN npm install --global pnpm@10.17.1
RUN cd /opt/templates && pnpm install --frozen-lockfile --prod

# --------------------------------------------------------------------
# Set ownership & permissions
RUN chmod +x /usr/local/bin/entrypoint.sh

# --------------------------------------------------------------------
# Set environment variables
ENV LISTEN_PORT="${LISTEN_PORT}"

# --------------------------------------------------------------------
# Expose Necessary Ports
EXPOSE ${LISTEN_PORT}

# --------------------------------------------------------------------
# This script will run when the container starts.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# --------------------------------------------------------------------
# Start the server
CMD ["node", "server.js"]