# Redcap on k8s #

This project contains a Dockerfile to containerize Redcap running on a LAMP server. It also contains configuration files to deploy this container on a k8s cluster.

## Secrets ##
**You should not store any passwords, credentials, or secret files in your repository**. We recommend you store all secret files, passwords, and credentials stored in LastPass or another secure password manager.

## Building the Docker Image ##

First build and [push the Docker image to GCR](https://cloud.google.com/container-registry/docs/pushing-and-pulling). Replace <PROJECT_ID> with the ID of the project you wish to deploy to. Run these commands from the base directory.

You will need to download the .zip file for Redcap version you want to install and place it in the code folder. Make sure to check the Dockerfile and replace the name of the Redcap zip with that of the zip you are using.

The Docker image is configured to use the America/Los_Angeles time zone. If you wish to use a different time zone you will need to change the TZ environment variable in the Dockerfile and also the date.timezone variable in the php.ini configuration file.

```shell
$ docker build -t gcr.io/<PROJECT_ID>/redcap:8.6.0 .
$ docker push gcr.io/<PROJECT_ID>/redcap:8.6.0
```

## Setting up the Database ##

[Create a MySQL CloudSQL instance](https://cloud.google.com/sql/docs/mysql/create-instance), login, and create a user and database for Redcap. If in production it is strongly advised that you enabled replication on the CloudSQL instance.

Change the name of the database and the user in the following SQL script to reflect the environment you are deploying to. Connect to your database and execute them. If you are deploying to multiple enviornments with the same CloudSQL instance then you will need to have different database names and usernames for each environment.

```sql
CREATE DATABASE redcap_db;
CREATE USER 'redcap_user'@'%' IDENTIFIED BY 'redcap_pass';
GRANT ALL PRIVILEGES ON redcap_db.* TO 'redcap_user'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

## Initializing k8s ##

### Creating the cluster ###

Create a k8s cluster, get the credentials, and create secrets. **If you are deploying multiple environments to the same cluster you only need to complete this step once.** Each deployment will have its own namespace in the same cluster.

```shell
$ gcloud container clusters create redcap --num-nodes 3 --scopes "https://www.googleapis.com/auth/projecthosting,compute-rw,storage-rw" --zone us-west1-a
$ gcloud container clusters get-credentials redcap
```

### Creating a namespace and adding secrets ###

If you are deploying multiple environments in the same cluster create a namespace and use this namespace for any commands where a namespace is specified. You will also need to edit all of the .yaml files in the redcap folder so that the namespace field under metadata reflects the namespace they are being deployed to.

```shell
$ kubectl create namespace redcap
```

Create the CloudSQL secrets. [Create a service account for the CloudSQL database and download the .json credentials](https://cloud.google.com/sql/docs/mysql/connect-kubernetes-engine). Use this .json file for credentials.json. Use the username, password, and database name that you created above for the cloudsql-db-credentials.

```shell
$ kubectl --namespace=redcap create secret generic cloudsql-instance-credentials --from-file=credentials.json=./secrets/downloaded-credentials.json
$ kubectl --namespace=redcap create secret generic cloudsql-db-credentials --from-literal=username=redcap_user --from-literal=password='redcap_pass' --from-literal=database=redcap_db
```

Create the secret containing Redcap's salt string. This just needs to be a random string 16 characters in length.

```shell
$ kubectl --namespace=redcap create secret generic redcap-server-secrets --from-literal=salt='<SALT_HERE>''
```

Create a secret for our mail forwarding configuration. We are using [SSMTP](https://wiki.debian.org/sSMTP) to relay mail from the Kubernetes pods to a mail relay. Create an ssmtp configuration file with the following format. [You can use this guide to find SMTP services that work to send mail from GKE](https://cloud.google.com/compute/docs/tutorials/sending-mail/).

```
root=redcap-email@yourdomain.org
mailhub=smtp-relay.gmail.com:587
AuthUser=redcap-email@yourdomain.org
AuthPass=<PASSWORD-HERE>
UseTLS=YES
UseSTARTTLS=YES
hostname=yourdomain.org
FromLineOverride=YES
```

And then create a secret with the configuration file.

```shell
$ kubectl --namespace=redcap create secret generic ssmtp-credentials --from-file=ssmtp.conf=./secrets/ssmtp-sendgrid.conf
```
## Create a Copy of k8s Configuration Files ##

Now that the cluster is created we will want to make a copy of all of our k8s configuration files so that we can keep track of changes specific to our new environment. Make a copy of the 'templates' folder in k8s and name it after the tier/environment you are deploying to (i.e staging, production)

## Shared Filesystem ##

Currently we are using GCS for a shared filesystem.

### Using GCS ###

[Create two new buckets](https://cloud.google.com/storage/docs/creating-buckets) in the GCS project where you are deploying redcap. One for temp data and one for user uploads.

Replace the names `redcap` and `redcap-temp` with the name of the buckets you just created in the files redcap-deployment.yaml and redcap-cron.yaml inside the Redcap folder in the folder containing your k8s configuration files. You will be replacing the bucket name in the following entry.

```yaml
lifecycle:
  postStart:
    exec:
      command:
        - "sh"
        - "-c"
        - >
          gcsfuse -o nonempty -o allow_other --dir-mode 777 --file-mode 777 redcap /mnt/redcap-bucket;
          gcsfuse -o nonempty -o allow_other --dir-mode 777 --file-mode 777 redcap-temp /var/www/site/temp;
```

### Using NFS ###

**Skip this section unless you wish to use NFS instead of GCS**. If you do, you may wish to look at using [Filestore](https://cloud.google.com/filestore/) instead of running your own NFS in your cluster.

If you decide to switch to NFS you should to remove the securityContext and lifecycle sections in the redcap-deployment.yaml and redcap-cron.yaml configuration files.

The following will guide you through provisioning an NFS server in the cluster backed by persistent storage. It is based on the contents of [this repo](https://github.com/kubernetes/examples/tree/master/staging/volumes/nfs).

Run the following commands. You can increase the size if the persistent disk if you desire. If we decide to use Filestore then skip these steps.

```shell
$ gcloud compute disks create redcap-nfs --zone us-west1-a --size 200GB
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-server-pv.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-server-pvc.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-server-rc.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-server-service.yaml
```

Persistent Volumes don't currently support using service names instead of IP addresses for NFS servers. Get the IP address for the NFS Server Service, and replace the IP address in the file nfs-pv.yaml with it. If we are using Filestore then use the NAS server's IP address instead of the IP for the NFS Server Service.

```shell
$ kubectl --namespace=redcap describe services nfs-server
```

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-pv.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/nfs/nfs-pvc.yaml
```

Add the following entry to the volumeMounts key under containers in redcap-cron.yaml and redcap-deployment.yaml in the redcap folder.

```yaml
 - name: nfs
   mountPath: /mnt/nfs
   readOnly: false
```

Add the following entry to the volume key under spec in redcap-cron.yaml and redcap-deployment.yaml in the redcap folder.

```
- name: nfs
  persistentVolumeClaim:
    claimName: nfs
```

You will also need to replace the entry for command with the following under the containers section in redcap-cron.yaml and redcap-deployment.yaml in the redcap folder. Replace `/usr/sbin/apache2ctl -D FOREGROUND` with `/usr/bin/php /var/www/site/cron.php` for the cron job.

```yaml
command: ["/bin/sh", "-c"]
args: ["chown -R www-data:www-data /mnt/nfs &&\
  /usr/sbin/apache2ctl -D FOREGROUND"]
```
## Deploying Redcap ##

Now that we have the cluster and shared filesystem setup, we can actually deploy the Redcap image we crated.

First, deploy the CloudSQL proxy. You will need to edit cloudsql-deployment.yaml and replace the project id, region, and instance name in the command string. You can find this on the instance details page for the CloudSQl instance under the Instance Connection Name field. It should be of the format project-id:region-name:instance-name.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/cloudsql/cloudsql-deployment.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/cloudsql/cloudsql-service.yaml
```

Next we will deploy Redcap. Replace the image tag in the k8s configuration files redcap-deployment.yaml and redcap-cron.yaml with the tag we used (i.e. gcr.io/<PROJECT_ID>/redcap:8.6.0). Then create the k8s deployments and services.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-deployment.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-service.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-cron.yaml
```

You can view the status of these workloads and services from the GKE page in the Cloud Console of the project you are deploying to.

## Automated TLS Certificates ##

Loosely following [this guide](https://blog.n1analytics.com/free-automated-tls-certificates-on-k8s/) for automated TLS certificates.

Setup Helm locally.

```bash
$ brew install kubernetes-helm
```

Or make sure it's up to date if already installed.

```bash
$ brew upgrade kubernetes-helm
```

Reserve an **unused/unbound** [reserved regional external IP from GCP](https://cloud.google.com/compute/docs/ip-addresses/reserve-static-external-ip-address) IP address for the nginx load balancer.

```bash
gcloud compute addresses create redcap-test --region <CLUSTER-REGION>
```

Install the nginx-ingress chart with the custom static IP. If you are installing multiple ingresses in the same culster you must name them differently.

```bash
$ helm repo add stable https://kubernetes-charts.storage.googleapis.com
$ helm repo update
$ helm install --namespace redcap nginx-ingress  stable/nginx-ingress --set controller.service.loadBalancerIP=<RESERVED-IP>
```

We can use the following command to check when our static IP has been assigned to the load balancer.

```shell
$ kubectl --namespace=redcap get services -o wide nginx-ingress-controller

NAME                       TYPE           CLUSTER-IP    EXTERNAL-IP     PORT(S)                      AGE       SELECTOR
nginx-ingress-controller   LoadBalancer   10.3.244.86   <STATIC-IP>   80:30624/TCP,443:31639/TCP   1h        app=nginx-ingress,component=controller,release=nginx-ingress
```

Once this is done, create the Redcap Ingress to be exposed by the load balancer. You will need to change the namespace, host, and the values in the tls field to match the domain name and namespace. You will not be able to access Redcap until certificates have been created.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-ingress.yaml
```

Point DNS to the IP address for the load balancer. Verify that it's working with either of the following:

```shell
$ dig redcap.<your-domain>.org
```

```shell
$ host -a redcap.<your-domain>.org
```

Follow [these instructions](https://docs.cert-manager.io/en/latest/getting-started/install/kubernetes.html#installing-with-helm) to install cert-manager on the cluster. At the time of writing, v0.12.0 was the most recent version. If you are upgrading from an older version of cert-manager, it can be easier to [uninstall](https://cert-manager.io/docs/installation/uninstall/kubernetes/) and then reinstall.

Once cert-manager is installed, go to the files located at `./k8s/templates/issuers/staging-issuer.yaml` and `./k8s/templates/production-issuer.yaml` and replace `your-email@here.com` with an email address where notifications about your SSL certificates should be sent.

Next, create the staging and production issuers on the cluster.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/issuers/staging-issuer.yaml
$ kubectl --namespace=redcap apply -f ./k8s/templates/templates/production-issuer.yaml
```

Now we can deploy our ingress with the cert-manager field uncommented. Edit the file `./k8s/templates/redcap/redcap-ingress.yaml`. Uncomment the staging cert-manager.io/issuer line that is shown below.

```
    cert-manager.io/issuer: "letsencrypt-staging"
```

Save and apply it.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-ingress.yaml
```

After applying the updated ingress, cert-manager should create a new certificate for you. You can check to make sure by running the following.

```
 kubectl --namespace=redcap get certificate

 NAME                       READY   SECRET                     AGE
redcap-<your-domain>-org-tls   True   redcap-<your-domain>-org-tls   11s
 ```

At this point we can check the status of the certificate with this command. It may fail the first time and take a minute or so for the domain to be validated and the certificate to be issued.

```shell
$ kubectl --namespace=redcap describe certificate redcap-<your-domain>-org-tls
```

We can watch the cert-manager logs to watch progress or see if anything has gone wrong.

```shell
$ kubectl logs deployment/cert-manager cert-manager --namespace cert-manager -f
```

Once we have successfully verified that certificate issuing is working with a stating issuer we can switch the ingress over to using the production issuer. Edit the file `./k8s/templates/redcap/redcap-ingress.yaml`. Comment out the staging cert-manager line:

```
    cert-manager.io/issuer: "letsencrypt-staging"
```

And then uncomment the production cert-manager line:

```
    cert-manager.io/issuer: "letsencrypt-prod"
```

Save and apply it.

```shell
$ kubectl --namespace=redcap apply -f ./k8s/templates/redcap/redcap-ingress.yaml
```

We can now check for and describe the updated certificate. Once it's ready, Redcap should have a valid certificate and be properly serving traffic over SSL!

```
 kubectl --namespace=redcap get certificate

 NAME                       READY   SECRET                     AGE
redcap-<your-domain>-org-tls   True   redcap-<your-domain>-org-tls   11s
 ```

```shell
$ kubectl --namespace=redcap describe certificate redcap-<your-domain>-org-tls
```

## Installing Redcap ##

At this point we will navigate to the path /install.php on our Redcap installation. From here follow instructions to setup the database for Redcap. Note: You should run the database creation commands through a client, as they may not run to completion through the GCloud UI.

## Post-installation Tasks ##

The primary user of Redcap will need to create administrator accounts.

Once this is done they will need to navigate to Control Center and then to File Upload Settings. From here they should set Storage Location to `Local` and set the path to `/mnt/redcap-bucket`.

They should then navigate to the Control Center again and then to the Configuration Check to make sure that the webserver has been deployed and configured correctly.

## Upgrading Redcap ##
First you will want to take the Redcap instance being upgraded offline. Navigate to the control center for the instance being upgraded, next to general configuration, and finally set the system status as offline.

Once the system is offline you will need to download the full install zip for version of Redcap you are upgrading to (e.g. redcap8.6.0.zip).
Download the zip, add it to the code folder in the redcap-k8s repository, and delete the old zip.

Next you will want to modify the Dockerfile to use the new zip. Change the unzip command to unzip the new redcap zip. In the below example you would replace `unzip /code/redcap8.1.5.zip` with `unzip /code/redcap8.6.0.zip`

```shell
RUN mkdir /var/www/site/ && \
    cd /code && unzip /code/redcap8.1.5.zip && \
    mv /code/redcap/* /var/www/site/ && \
    mv /code/database.php /var/www/site/database.php && \
    mv /code/apache-config.conf /etc/apache2/sites-enabled/000-default.conf && \
    mv /code/php.ini /etc/php/7.0/apache2/php.ini
```

Once you have updated the Dockerfile you will need to build a new Docker image and push it to the GCR in your project.

```shell
docker build -t gcr.io/<PROJECT_ID>/redcap:8.6.0 .
```

```shell
docker push gcr.io/<PROJECT_ID>/redcap:8.6.0
```
Next you will update the k8s deployment file `redcap-deployment.yaml`. You will hcange the image to point to the new image you just built and temporarily remove the liveliness probe and readiness probe. The liveliness and readiness probes need to be removed during the upgrade because the endpoints that are used by these probes will be broken until the upgrade is complete. To remove the liveliness and readiness probes you will remove these sections from the `redcap-deployment.yaml` file.

```
livenessProbe:
  httpGet:
    path: /
    port: 80
  initialDelaySeconds: 30
  timeoutSeconds: 1
readinessProbe:
  httpGet:
    path: /
    port: 80
  initialDelaySeconds: 30
  timeoutSeconds: 1
```

Once this is done you can update the deployment with the following command:

```
kubectl --namespace=redcap apply -f ./redcap-deployment.yaml
```

You can check the status of the new pods by looking at the redcap-deployment service in the Kubernetes Engine page on the Google Cloud console or by running the following command.

```
kubectl --namespace=redcap get pods
```

Once the new pods are up navigate to the upgrade module in the new version. For example, when upgrading to 8.6.0 we would navigate to `https://redcap.yourdomain.org/redcap_v8.6.0/upgrade.php`
Once here, follow the instructions on this page.

When following the instructions you may need to copy an SQL script and execute it. The easiest way to accomplish this is to connect to the CloudSQL instance through the Google Cloud Console, but any SQL client should suffice. In some instances, long upgrade scripts might not execute correctly on the Google Cloud Console.

Once you have followed the instructions on the upgrade page you should be done updating to the new version of Redcap and forwarded back to the login page. At this point you should login and do a quick check to make sure everything is working as expected.

Next, we want to add the liveliness and readiness probes back to k8s/redcap-deployment.yaml and update the deployment again.

Once the deployment is updated we can update the cron job file `redcap-cron.yaml` with the new image and deploy the change with the following command.

```
kubectl --namespace=redcap apply -f ./k8s/redcap-cron.yaml
```

In Redcap, navigate to the configuration check (e.g. https://redcap.yourdomain.org/redcap_v8.6.0/ControlCenter/check.php) to make sure that there are no problems with dependencies or configuration. This is unlikely, but if there are you will probably need to install new dependencies in the Docker image and re-deploy.

At this point we are done making changes to our Kubernetes configurations. Add and commit all of the changes you just made to your repository.

Now that we are done with the upgrade we should put the Redcap instance back online. Navigate to the control center, then general configuration, then set the system status as online.

You are now done with the update!

## License ##

This repository is made available and distributed under the GPLv3 license.
