#!/bin/bash
PROGRESS_FILE=/tmp/dependancy_asuswrt_in_progress
if [ ! -z $1 ]; then
	PROGRESS_FILE=$1
fi
touch ${PROGRESS_FILE}
echo 0 > ${PROGRESS_FILE}
echo "********************************************************"
echo "*             Installation des dépendances             *"
echo "********************************************************"
sudo apt-get update
echo 10 > ${PROGRESS_FILE}
echo "Installation des dépendances apt"
sudo apt-get -y install python3-dev python3-pip

echo 30 > ${PROGRESS_FILE}
if [ $(pip3 list | grep pexpect | wc -l) -eq 0 ]; then
    echo "Installation du module pexpect pour python"
    sudo pip3 install pexpect
fi

echo 100 > /${PROGRESS_FILE}
echo "********************************************************"
echo "*             Installation terminée                    *"
echo "********************************************************"
rm ${PROGRESS_FILE}
