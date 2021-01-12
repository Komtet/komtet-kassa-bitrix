export VERSION=$1

START_PATH="$PWD"
PROJECT_DIR="komtet.kassa"
PROJECT_TAR="$PROJECT_DIR.tar.gz"
DIST_MARKET_DIR="dist/market"
DIST_GITHUB_DIR="dist/github"
VERSION_DIR="$VERSION"
VERSION_TAR="$VERSION.tar.gz"


# Colors
COLOR_OFF="\033[0m"
RED="\033[1;31m"
YELLOW="\e[33m"
CYAN="\033[1;36m"


echo -e "${CYAN}Cборка обновлений для загрузок в маркетплейс/github${COLOR_OFF}\n"

LAST_TAG=$(git tag -l | tail -1)
[ -z "$LAST_TAG" ] && { echo -e "${RED}Последний тег не найден${COLOR_OFF}"; exit 1; }
echo -e "${CYAN}Текущая версия проекта: ${YELLOW}${LAST_TAG}${COLOR_OFF}"

PREVIOUS_TAG=$(git tag -l | tail -2 | head -1)
[ -z "$PREVIOUS_TAG" ] && { echo -e "${RED}Предпоследний тег не найден${COLOR_OFF}"; exit 1; }
echo -e "${CYAN}Предпоследняя версия проекта: ${YELLOW}${PREVIOUS_TAG}${COLOR_OFF}\n"

DIFFS=($(git diff $LAST_TAG $PREVIOUS_TAG --name-only| grep komtet.kassa))
echo -e "\n${CYAN}Обнаружены отличия в файлах: ${COLOR_OFF}"

#Архивирование для github
mkdir -p "$DIST_GITHUB_DIR"
tar --exclude=$PROJECT_DIR'/lib/komtet-kassa-php-sdk/.*' \
   -czf $PROJECT_TAR $PROJECT_DIR
mv $PROJECT_TAR $DIST_GITHUB_DIR

#Архивирование для маркетплейса
mkdir -p "$DIST_MARKET_DIR"
for element in "${DIFFS[@]}"
do
   DIRNAME="$DIST_MARKET_DIR/$(dirname ${element})"
   echo -e "${RED} * ${element} ${COLOR_OFF}"
   mkdir -p $DIRNAME && cp -r ${element} $DIRNAME
done

mv "$DIST_MARKET_DIR/$PROJECT_DIR" "$DIST_MARKET_DIR/$VERSION_DIR"
cd $DIST_MARKET_DIR && tar -czf $VERSION_TAR $VERSION_DIR && rm -rf $VERSION_DIR && cd -

echo -e "\n${CYAN}Сборка обновлений завершена.${COLOR_OFF}"
echo -e "${CYAN}Для маркетплейса: ${YELLOW}${DIST_MARKET_DIR}/${VERSION_TAR}${COLOR_OFF}"
echo -e "${CYAN}Для GitHub: ${YELLOW}${DIST_GITHUB_DIR}/${PROJECT_TAR}${COLOR_OFF}"
