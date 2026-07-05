# backup — nightly pg_dump of all six databases (CONTRACTS §4)
# alpine + postgresql16-client (matches brain-db pg16) + busybox crond.
FROM alpine:3.20

RUN apk add --no-cache postgresql16-client bash tzdata \
 && cp /usr/share/zoneinfo/Europe/Stockholm /etc/localtime \
 && echo "Europe/Stockholm" > /etc/timezone

ENV TZ=Europe/Stockholm

COPY backup.sh /usr/local/bin/backup.sh
RUN sed -i 's/\r$//' /usr/local/bin/backup.sh \
 && chmod +x /usr/local/bin/backup.sh \
 && echo "30 3 * * * /usr/local/bin/backup.sh >> /proc/1/fd/1 2>&1" > /etc/crontabs/root

# crond in foreground; container logs show each nightly run
CMD ["crond", "-f", "-l", "2"]
