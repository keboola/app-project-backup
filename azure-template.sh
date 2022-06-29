#!/bin/bash

az login >> /dev/null

echo "Enter your subscription ID:"
read subscriptionId

echo "Enter the Resource Group name:"
read resourceGroupName

az account set --subscription $subscriptionId

STORAGE_ACCOUNT=$(
  az deployment group create \
   --resource-group $resourceGroupName \
   --template-file "./azure-template.json"
)

ACCOUNT_NAME=$(echo $STORAGE_ACCOUNT | jq -r '.properties.outputs.storageAccountName.value')
LOCATION=$(echo $STORAGE_ACCOUNT | jq -r '.properties.parameters.location.value')

ACCOUNT_ACCOUNT_KEY=$(
  az storage account keys list \
    --account-name "$ACCOUNT_NAME" \
    --resource-group $resourceGroupName \
    --query "[].value | [0]" \
    --output tsv
)

echo "TEST_AZURE_ACCOUNT_NAME=\"$ACCOUNT_NAME\""
echo "TEST_AZURE_ACCOUNT_KEY=\"$ACCOUNT_ACCOUNT_KEY\""
echo "TEST_AZURE_REGION=\"$LOCATION\""