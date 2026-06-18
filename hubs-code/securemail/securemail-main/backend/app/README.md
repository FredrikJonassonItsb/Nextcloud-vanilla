# Secure Mail
This application consists of a backend and a frontend.

The backend is a Node.js application that uses the Express framework. It is responsible for handling the incoming requests and sending the responses. It is run in Docker.

## Development
For the backend, copy `.env.example` to `.env` and set the environment variables. Then, run the backend:
```bash
$ pnpm install
$ pnpm run dev
```

## Deployment
Build the Docker image for the backend:
```bash
$ cd backend
$ docker build -t secure-mail-backend .
$ docker run \
   --restart unless-stopped \
   --detach \
   --publish 127.0.0.1:3000:3000 \
   --env IMAP_HOST=<your IMAP server> \
   --env IMAP_PORT=<your IMAP port> \
   --env SMTP_HOST=<your SMTP server> \
   --env SMTP_PORT=<your SMTP port> \
   --name backend \
   secure-mail-backend
```
