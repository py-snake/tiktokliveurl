SCAN_DIR=~/source/repos/tiktokliveurl/

if [ -z "$SONAR_TOKEN" ]; then
  if [ -f .sonar-token ]; then
    SONAR_TOKEN=$(cat .sonar-token)
  else
    echo "Error: SONAR_TOKEN not set and .sonar-token file not found." >&2
    exit 1
  fi
fi

SONAR_TOKEN="$SONAR_TOKEN" \
  podman-compose -f ~/programs/dev/sonarscanner/docker-compose.yml run --rm scanner