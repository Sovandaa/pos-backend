<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function listOrders()
    {
        return Order::orderBy('id', 'desc')->get();
    }

    public function createOrder(array $payload): array
    {
        $data = $this->validateOrderPayload($payload);
        $grouped = $this->groupItems($data['items']);

        $order = DB::transaction(function () use ($data, $grouped) {
            $products = $this->loadProductsWithLock(array_keys($grouped));
            $this->ensureSufficientStock($products, $grouped);

            [$lineItems, $subtotal] = $this->buildLineItemsAndSubtotal($products, $grouped);
            $this->decrementStock($products, $grouped);

            $tax = isset($data['tax']) ? (float) $data['tax'] : 0.0;
            $total = round($subtotal + $tax, 2);

            return Order::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'items' => $lineItems,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'status' => OrderStatus::Pending,
            ]);
        });

        return $this->buildReceiptPayload($order);
    }

    public function getOrderWithReceipt(int $id): array
    {
        $order = Order::findOrFail($id);
        return $this->buildReceiptPayload($order);
    }

    public function updateOrderStatus(int $id, OrderStatus $status): Order
    {
        $order = Order::findOrFail($id);

        if ($status === OrderStatus::Canceled && $order->status !== OrderStatus::Canceled) {
            DB::transaction(fn () => $this->restoreStock($order));
        }

        $order->update(['status' => $status]);
        return $order;
    }

    public function cancelOrder(int $id): Order
    {
        $order = Order::findOrFail($id);
        if ($order->status === OrderStatus::Canceled) {
            abort(409, 'Order already canceled');
        }

        // Reuse the same path as generic status update
        return $this->updateOrderStatus($id, OrderStatus::Canceled);
    }

    public function getReceiptByOrderNumber(string $orderNumber): array
    {
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        return $this->buildReceiptPayload($order);
    }

    private function validateOrderPayload(array $payload): array
    {
        // Controller-level validation is primary; this is a safety net if service used directly
        return validator($payload, [
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'tax' => ['nullable', 'numeric', 'min:0'],
        ])->validate();
    }

    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            Product::where('id', $item['product_id'])
                ->lockForUpdate()
                ->increment('stock', (int) $item['quantity']);
        }
    }

    private function generateOrderNumber(): string
    {
        do {
            $candidate = 'ORD-' . now()->format('Ymd-His') . '-' . Str::upper(Str::random(4));
        } while (Order::where('order_number', $candidate)->exists());
        return $candidate;
    }

    private function buildReceiptPayload(Order $order): array
    {
        $status = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        $lines = [
            "Receipt #{$order->order_number}",
            $order->customer_name ? "Customer: {$order->customer_name}" : null,
            $order->customer_email ? "Email: {$order->customer_email}" : null,
            "Status: {$status}",
            "Date: " . $order->created_at?->toDateTimeString(),
            str_repeat('-', 30),
        ];
        $lines = array_values(array_filter($lines));

        foreach ($order->items as $item) {
            $lines[] = sprintf(
                '%s x%d @ %s = %s',
                $item['name'],
                (int) $item['quantity'],
                number_format((float) $item['price'], 2),
                number_format((float) $item['line_total'], 2)
            );
        }

        $lines[] = str_repeat('-', 30);
        $lines[] = 'Subtotal: ' . number_format((float) $order->subtotal, 2);
        $lines[] = 'Tax: ' . number_format((float) $order->tax, 2);
        $lines[] = 'Total: ' . number_format((float) $order->total, 2);

        return [
            'order' => $order,
            'receipt' => [
                'text' => implode("\n", $lines),
                'items' => $order->items,
                'subtotal' => (float) $order->subtotal,
                'tax' => (float) $order->tax,
                'total' => (float) $order->total,
            ],
        ];
    }

    private function groupItems(array $items): array
    {
        $grouped = [];
        foreach ($items as $row) {
            $pid = (int) $row['product_id'];
            $qty = (int) $row['quantity'];
            $grouped[$pid] = ($grouped[$pid] ?? 0) + $qty;
        }
        return $grouped; // [product_id => quantity]
    }

    private function loadProductsWithLock(array $ids)
    {
        return Product::whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function ensureSufficientStock($products, array $grouped): void
    {
        foreach ($grouped as $productId => $quantity) {
            $product = $products[$productId] ?? null;
            if (!$product) {
                abort(422, "Product {$productId} not found");
            }
            if ((int) $product->stock < (int) $quantity) {
                abort(422, "Insufficient stock for {$product->name}");
            }
        }
    }

    private function buildLineItemsAndSubtotal($products, array $grouped): array
    {
        $lineItems = [];
        $subtotal = 0.0;

        foreach ($grouped as $productId => $quantity) {
            $product = $products[$productId];
            $lineTotal = round((float) $product->price * (int) $quantity, 2);
            $lineItems[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'quantity' => (int) $quantity,
                'line_total' => (float) $lineTotal,
            ];
            $subtotal = round($subtotal + $lineTotal, 2);
        }

        return [$lineItems, $subtotal];
    }

    private function decrementStock($products, array $grouped): void
    {
        foreach ($grouped as $productId => $quantity) {
            $products[$productId]->decrement('stock', (int) $quantity);
        }
    }
}
