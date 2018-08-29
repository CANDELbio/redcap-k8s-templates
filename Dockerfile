FROM debian:stretch

# Install apache, PHP, and supplimentary programs. openssh-server, curl, and lynx-cur are for debugging the container.
RUN apt-get update && apt-get -y upgrade && DEBIAN_FRONTEND=noninteractive apt-get -y install \
    apache2 php7.0 php7.0-mysql php7.0-curl php7.0-xml php7.0-mbstring php7.0-zip php7.0-gd libapache2-mod-php7.0 curl \
    lynx-cur unzip ssmtp mailutils tzdata

# Enable apache mods.
RUN a2enmod php7.0
RUN a2enmod rewrite

RUN useradd -ms /bin/bash redcap
RUN usermod -g www-data redcap

COPY code /code

RUN mkdir /var/www/site/ && \
    cd /code && unzip /code/redcap8.6.0.zip && \
    mv /code/redcap/* /var/www/site/ && \
    mv /code/database.php /var/www/site/database.php && \
    mv /code/apache-config.conf /etc/apache2/sites-enabled/000-default.conf && \
    mv /code/php.ini /etc/php/7.0/apache2/php.ini && \
    mv /code/envvars /etc/apache2/envvars

RUN chown -R redcap:www-data /var/www/site

# Install GCSfuse for shared storage
RUN apt-get update && apt-get install --yes --no-install-recommends \
    ca-certificates gnupg \
  && echo "deb http://packages.cloud.google.com/apt gcsfuse-stretch main" \
    | tee /etc/apt/sources.list.d/gcsfuse.list \
  && curl https://packages.cloud.google.com/apt/doc/apt-key.gpg | apt-key add - \
  && apt-get update \
  && apt-get install --yes gcsfuse

# Make the directory for mounting GCS.
RUN mkdir /mnt/redcap-bucket

#Set the timezone to Pacific
ENV TZ America/Los_Angeles

# Expose apache.
EXPOSE 80

# By default start up apache in the foreground, override with /bin/bash for interative.
CMD /usr/sbin/apache2ctl -D FOREGROUND