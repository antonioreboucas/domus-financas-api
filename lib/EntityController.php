<?php

require_once __DIR__ . '/EntityRegistry.php';

// Controller CRUD genérico usado por todas as entidades (list/filter/get/
// create/update/delete/bulkCreate). Toda entidade registrada é
// 'user_scoped': o escopo é sempre o usuário autenticado, nunca um valor
// vindo do client (ver scopeFilter()/insertRow()).
class EntityController
{
    private static array $columnsCache = [];

    private static function tableColumns(string $table): array
    {
        if (!isset(self::$columnsCache[$table])) {
            $stmt = Database::get()->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            self::$columnsCache[$table] = $stmt->fetchAll();
        }
        return self::$columnsCache[$table];
    }

    private static function knownColumns(string $table): array
    {
        return array_column(self::tableColumns($table), 'COLUMN_NAME');
    }

    private static function jsonColumns(string $table): array
    {
        $jsonCols = array_filter(self::tableColumns($table), fn($c) => $c['DATA_TYPE'] === 'json');
        return array_column($jsonCols, 'COLUMN_NAME');
    }

    private static function dateColumns(string $table): array
    {
        $dateCols = array_filter(self::tableColumns($table), fn($c) => in_array($c['DATA_TYPE'], ['date', 'datetime'], true));
        return array_column($dateCols, 'COLUMN_NAME');
    }

    // Formulários do frontend mandam "" pra campos de data opcionais não
    // preenchidos (ex: data_validade). MySQL em modo estrito rejeita "" em
    // coluna DATE/DATETIME — só aceita NULL. Sem isso, salvar um produto sem
    // validade, por exemplo, quebraria com erro de banco.
    private static function normalizeEmptyDates(array $data, array $dateCols): array
    {
        foreach ($dateCols as $col) {
            if (array_key_exists($col, $data) && $data[$col] === '') {
                $data[$col] = null;
            }
        }
        return $data;
    }

    // Decodifica colunas JSON de volta pra array. Não confia só em jsonCols()
    // (schema) porque no MariaDB colunas JSON são LONGTEXT disfarçado — o
    // INFORMATION_SCHEMA não distingue. Em vez disso, olha o conteúdo: toda
    // coluna JSON deste schema guarda um array (nunca objeto solto), então
    // "começa com [ e termina com ]" + parse válido é um sinal seguro,
    // funciona igual em MySQL de verdade e em MariaDB.
    private static function decodeRow(array $row, array $jsonCols): array
    {
        foreach ($row as $col => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $looksLikeJsonArray = $value[0] === '[' && substr(rtrim($value), -1) === ']';
            if (!in_array($col, $jsonCols, true) && !$looksLikeJsonArray) {
                continue;
            }
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $row[$col] = $decoded;
            }
        }
        return $row;
    }

    // Body de POST em lote (bulkCreate) chega como uma lista JSON de objetos
    // em vez de um único objeto. array_is_list() só existe a partir do PHP 8.1,
    // então checamos na mão pra continuar compatível com PHP 8.0.
    public static function isListPayload($body): bool
    {
        if (!is_array($body) || $body === []) {
            return false;
        }
        return array_keys($body) === range(0, count($body) - 1);
    }

    private static function requireEntity(string $entityName): array
    {
        $def = EntityRegistry::get($entityName);
        if (!$def) {
            Response::error("Entidade desconhecida: $entityName", 404);
        }
        return $def;
    }

    // Escopo de LEITURA — toda entidade registrada é 'user_scoped', então
    // toda leitura exige usuário autenticado e é sempre restrita às linhas
    // do próprio usuário (o valor de user_id vindo do client, se houver, é
    // sobrescrito — nunca confiado).
    private static function scopeFilter(array $entityDef, array $filter, ?array $currentUser): array
    {
        if (!$currentUser) {
            Response::error('Não autenticado', 401);
        }
        $filter['user_id'] = $currentUser['id'];
        return $filter;
    }

    // Permissão de ESCRITA (create/update/delete). scopeFilter() garante que
    // update/delete só enxergam linhas do próprio usuário; aqui só resta
    // exigir que exista sessão.
    private static function requireWritePermission(?array $currentUser): void
    {
        if (!$currentUser) {
            Response::error('Não autenticado', 401);
        }
    }

    private static function buildWhere(array $filter, array $knownCols): array
    {
        $clauses = [];
        $params = [];
        $opMap = ['$gte' => '>=', '$lte' => '<=', '$gt' => '>', '$lt' => '<', '$ne' => '!='];
        $i = 0;

        foreach ($filter as $field => $value) {
            if (!in_array($field, $knownCols, true)) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $op => $opValue) {
                    if (!isset($opMap[$op])) {
                        continue;
                    }
                    $ph = ":p" . $i++;
                    $clauses[] = "`$field` {$opMap[$op]} $ph";
                    $params[$ph] = $opValue;
                }
            } else {
                $ph = ":p" . $i++;
                $clauses[] = "`$field` = $ph";
                $params[$ph] = $value;
            }
        }

        return [$clauses, $params];
    }

    private static function buildOrder(?string $sort, array $knownCols): string
    {
        if (!$sort) {
            return '';
        }
        $desc = str_starts_with($sort, '-');
        $field = $desc ? substr($sort, 1) : $sort;
        if (!in_array($field, $knownCols, true)) {
            return '';
        }
        return " ORDER BY `$field` " . ($desc ? 'DESC' : 'ASC');
    }

    public static function query(string $entityName, array $filter, ?string $sort, ?int $limit, ?array $currentUser): array
    {
        $def = self::requireEntity($entityName);
        $table = $def['table'];
        $knownCols = self::knownColumns($table);

        $filter = self::scopeFilter($def, $filter, $currentUser);
        [$clauses, $params] = self::buildWhere($filter, $knownCols);

        $sql = "SELECT * FROM `$table`";
        if ($clauses) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        $sql .= self::buildOrder($sort, $knownCols);
        $sql .= ' LIMIT ' . (int) ($limit ?: 1000);

        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $jsonCols = self::jsonColumns($table);
        return array_map(fn($row) => self::decodeRow($row, $jsonCols), $rows);
    }

    public static function get(string $entityName, string $id, ?array $currentUser): ?array
    {
        $rows = self::query($entityName, ['id' => $id], null, 1, $currentUser);
        return $rows[0] ?? null;
    }

    // Busca por id sem aplicar escopo. Usado só logo após um create()/bulkCreate()
    // bem-sucedido, cujo dono já foi determinado e gravado no INSERT — evita
    // uma segunda ida ao banco com WHERE user_id = ... redundante.
    private static function rawGetById(string $entityName, string $id): ?array
    {
        $def = self::requireEntity($entityName);
        $table = $def['table'];
        $stmt = Database::get()->prepare("SELECT * FROM `$table` WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::decodeRow($row, self::jsonColumns($table));
    }

    // Monta e executa o INSERT de uma linha; devolve o id gerado. Compartilhado
    // por create() (uma linha) e bulkCreate() (várias, mesma regra de negócio).
    private static function insertRow(array $def, array $data, array $currentUser): string
    {
        $table = $def['table'];
        $knownCols = self::knownColumns($table);
        $jsonCols = self::jsonColumns($table);

        unset($data['id'], $data['user_id'], $data['created_date'], $data['updated_date']);
        $data = self::normalizeEmptyDates($data, self::dateColumns($table));

        $data['user_id'] = $currentUser['id'];
        $data['id'] = Uuid::v4();

        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($data as $field => $value) {
            if ($field !== 'id' && !in_array($field, $knownCols, true)) {
                continue;
            }
            // is_array() como rede de segurança além da detecção via schema:
            // no MariaDB (comum em hospedagem compartilhada), colunas JSON
            // aparecem como LONGTEXT no INFORMATION_SCHEMA, então jsonCols()
            // não as encontra — sem isso, um array PHP ia direto pro PDO e
            // virava "Array to string conversion".
            if ($value !== null && (is_array($value) || in_array($field, $jsonCols, true))) {
                $value = json_encode($value);
            }
            $columns[] = "`$field`";
            $placeholders[] = ":$field";
            $params[":$field"] = $value;
        }

        $sql = "INSERT INTO `$table` (" . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        Database::get()->prepare($sql)->execute($params);

        return $data['id'];
    }

    public static function create(string $entityName, array $data, ?array $currentUser): array
    {
        $def = self::requireEntity($entityName);
        self::requireWritePermission($currentUser);

        $id = self::insertRow($def, $data, $currentUser);
        return self::rawGetById($entityName, $id);
    }

    // Equivalente a entities.X.bulkCreate([...]) do SDK do Base44 — usado no
    // seed de dados iniciais de uma entidade. Mesma regra de permissão/escopo
    // do create() de uma linha, só que dentro de uma única transação.
    public static function bulkCreate(string $entityName, array $rows, ?array $currentUser): array
    {
        $def = self::requireEntity($entityName);
        self::requireWritePermission($currentUser);

        $db = Database::get();
        $db->beginTransaction();
        try {
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ids[] = self::insertRow($def, $row, $currentUser);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return array_map(fn($id) => self::rawGetById($entityName, $id), $ids);
    }

    public static function update(string $entityName, string $id, array $data, ?array $currentUser): array
    {
        $def = self::requireEntity($entityName);
        self::requireWritePermission($currentUser);

        $existing = self::get($entityName, $id, $currentUser);
        if (!$existing) {
            Response::error('Registro não encontrado', 404);
        }

        $table = $def['table'];
        $knownCols = self::knownColumns($table);
        $jsonCols = self::jsonColumns($table);

        // user_id nunca é reatribuível via update — só o create() (sempre com
        // o usuário autenticado) decide o dono de uma linha.
        unset($data['id'], $data['user_id'], $data['created_date'], $data['updated_date']);
        $data = self::normalizeEmptyDates($data, self::dateColumns($table));

        $sets = [];
        $params = [':id' => $id];
        foreach ($data as $field => $value) {
            if (!in_array($field, $knownCols, true)) {
                continue;
            }
            if ($value !== null && (is_array($value) || in_array($field, $jsonCols, true))) {
                $value = json_encode($value);
            }
            $sets[] = "`$field` = :$field";
            $params[":$field"] = $value;
        }

        if ($sets) {
            $sql = "UPDATE `$table` SET " . implode(',', $sets) . ' WHERE id = :id';
            Database::get()->prepare($sql)->execute($params);
        }

        return self::get($entityName, $id, $currentUser);
    }

    public static function delete(string $entityName, string $id, ?array $currentUser): void
    {
        $def = self::requireEntity($entityName);
        self::requireWritePermission($currentUser);

        $existing = self::get($entityName, $id, $currentUser);
        if (!$existing) {
            Response::error('Registro não encontrado', 404);
        }

        $stmt = Database::get()->prepare("DELETE FROM `{$def['table']}` WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}
