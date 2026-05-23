<?php
declare(strict_types=1);

class LambdaClientFake extends Restart_Registry_Lambda_Client {
    private array     $items       = [];
    private ?WP_Error $error       = null;
    private ?WP_Error $updateError = null;
    private array     $calls       = [];

    public function __construct() {
        // skip parent — no WP calls
    }

    public function setItem(int $id, array $item): void {
        $this->items[$id] = array_merge(['id' => $id], $item);
    }

    public function setError(WP_Error $error): void {
        $this->error = $error;
    }

    public function setUpdateError(WP_Error $error): void {
        $this->updateError = $error;
    }

    public function getCalls(): array {
        return $this->calls;
    }

    public function get_item(int $item_id) {
        $this->calls[] = ['method' => 'get_item', 'args' => [$item_id]];
        if ($this->error) return $this->error;
        return $this->items[$item_id] ?? null;
    }

    public function get_items(array $item_ids): array {
        $this->calls[] = ['method' => 'get_items', 'args' => [$item_ids]];
        return array_values(array_filter(
            array_map(fn($id) => $this->items[$id] ?? null, $item_ids)
        ));
    }

    public function create_item(array $data) {
        $this->calls[] = ['method' => 'create_item', 'args' => [$data]];
        if ($this->error) return $this->error;
        $id = count($this->items) + 1;
        $this->items[$id] = array_merge(['id' => $id], $data);
        return $this->items[$id];
    }

    public function update_item(int $item_id, array $data) {
        $this->calls[] = ['method' => 'update_item', 'args' => [$item_id, $data]];
        if ($this->updateError) return $this->updateError;
        if ($this->error) return $this->error;
        $item = $this->items[$item_id] ?? ['id' => $item_id];
        $this->items[$item_id] = array_merge($item, $data);
        return $this->items[$item_id];
    }

    public function delete_item(int $item_id) {
        $this->calls[] = ['method' => 'delete_item', 'args' => [$item_id]];
        if ($this->error) return $this->error;
        $item = $this->items[$item_id] ?? null;
        unset($this->items[$item_id]);
        return $item;
    }
}
