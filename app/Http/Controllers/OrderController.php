<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    public function index()
    {
        return response()->json($this->orders->listOrders());
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'tax' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->orders->createOrder($payload);
        return response()->json($result, 201);
    }

    public function show(int $id)
    {
        return response()->json($this->orders->getOrderWithReceipt($id));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,paid,completed,canceled'],
        ]);

        $order = $this->orders->updateOrderStatus($id, $data['status']);
        return response()->json($order);
    }

    public function cancel(int $id)
    {
        $order = $this->orders->cancelOrder($id);
        return response()->json(['message' => 'Order canceled', 'order' => $order]);
    }

    public function receiptByNumber(string $orderNumber)
    {
        return response()->json($this->orders->getReceiptByOrderNumber($orderNumber));
    }
}
