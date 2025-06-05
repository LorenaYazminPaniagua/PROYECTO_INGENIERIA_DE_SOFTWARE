<?php
session_start();
$conn = new mysqli("localhost", "root", "", "HerreriaUG");
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$idEmpleado = $_SESSION['id'] ?? 1;
$conn->query("SET @id_empleado_sesion := $idEmpleado");

// Función para limpiar resultados
function limpiarResultadosPendientes($conn) {
    while ($conn->more_results() && $conn->next_result()) {
        $res = $conn->store_result();
        if ($res instanceof mysqli_result) {
            $res->free();
        }
    }
}

$clientes = [];
$sql = "SELECT c.idCliente AS id, p.Nombre
        FROM Clientes c
        INNER JOIN Personas p ON c.idPersona = p.idPersona
        WHERE p.Estatus = 1";
$result = $conn->query($sql);
if ($result) {
    $clientes = $result->fetch_all(MYSQLI_ASSOC);
}

$cliente_id = $_POST['cliente_id'] ?? '';
$tipo_cliente = 'normal';
$mensaje_error = '';
$mensaje_exito = '';
$productos_registrados = [];
$termino_busqueda = $_POST['termino'] ?? '';
$es_credito = isset($_POST['es_credito']);

// Obtener tipo de cliente
if (!empty($cliente_id)) {
    $stmtTipoCliente = $conn->prepare("
        SELECT TRIM(d.Categoria) AS Categoria 
        FROM Clientes c 
        JOIN Descuentos d ON c.idDescuento = d.idDescuento 
        WHERE c.idCliente = ?
    ");
    $stmtTipoCliente->bind_param("i", $cliente_id);
    $stmtTipoCliente->execute();
    $resTipo = $stmtTipoCliente->get_result();
    if ($rowTipo = $resTipo->fetch_assoc()) {
        $tipo_cliente = strtolower(trim($rowTipo['Categoria']));
    }
    $stmtTipoCliente->close();
    limpiarResultadosPendientes($conn);
}

// Buscar producto
if (isset($_POST['buscar'])) {
    $termino = trim($_POST['termino']);
    $producto = null;

    $stmt = $conn->prepare("CALL BuscarProductoPorNombre(?)");
    $stmt->bind_param("s", $termino);
    $stmt->execute();
    $resultadoNombre = $stmt->get_result();
    $producto = $resultadoNombre->fetch_assoc();
    $stmt->close();
    limpiarResultadosPendientes($conn);

    if (!$producto) {
        $stmt = $conn->prepare("CALL BuscarProductoPorCodigoBarras(?)");
        $stmt->bind_param("s", $termino);
        $stmt->execute();
        $resultadoCodigo = $stmt->get_result();
        $producto = $resultadoCodigo->fetch_assoc();
        $stmt->close();
        limpiarResultadosPendientes($conn);
    }

    if ($producto) {
        $cantidad = 1;
        $idProducto = $producto['idProducto'];

        $stmtPrecio = $conn->prepare("CALL CalcularPrecioConDescuento(?, ?, @precioFinal)");
        $stmtPrecio->bind_param("is", $idProducto, $tipo_cliente);
        $stmtPrecio->execute();
        $stmtPrecio->close();
        limpiarResultadosPendientes($conn);

        $res = $conn->query("SELECT @precioFinal AS precioConDescuento");
        $row = $res->fetch_assoc();
        $precioConDescuento = floatval($row['precioConDescuento'] ?? 0.0);

        $stmt = $conn->prepare("CALL AgregarAlCarrito(?, ?, ?, ?)");
        $stmt->bind_param("iiid", $idEmpleado, $idProducto, $cantidad, $precioConDescuento);
        $stmt->execute();
        $stmt->close();
        limpiarResultadosPendientes($conn);
    } else {
        $mensaje_error = "Producto no encontrado.";
    }
}

// Sumar
if (isset($_POST['sumar'])) {
    $idProducto = (int)($_POST['producto_id'] ?? 0);
    if ($idProducto > 0) {
        $stmt = $conn->prepare("CALL SumarCantidadProductoCarrito(?, ?)");
        $stmt->bind_param("ii", $idEmpleado, $idProducto);
        $stmt->execute();
        $stmt->close();
        limpiarResultadosPendientes($conn);
    }
}

// Restar
if (isset($_POST['restar'])) {
    $idProducto = (int)($_POST['producto_id'] ?? 0);
    if ($idProducto > 0) {
        $stmt = $conn->prepare("CALL RestarCantidadProductoCarrito(?, ?)");
        $stmt->bind_param("ii", $idEmpleado, $idProducto);
        $stmt->execute();
        $stmt->close();
        limpiarResultadosPendientes($conn);
    }
}

// Obtener carrito
$stmt = $conn->prepare("CALL sp_ObtenerCarritoPorEmpleado(?)");
$stmt->bind_param("i", $idEmpleado);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $productos_registrados[] = [
        'idProducto' => $row['idProducto'],
        'nombre' => $row['Nombre'],
        'cantidad' => $row['Cantidad'],
        'precioProducto' => $row['PrecioVenta'],
        'precioVenta' => $row['Total'],
    ];
}
$stmt->close();
limpiarResultadosPendientes($conn);

// Procesar pedido
if (isset($_POST['procesar'])) {
    if (empty($cliente_id)) {
        $mensaje_error = "Debe seleccionar un cliente.";
    } elseif (empty($productos_registrados)) {
        $mensaje_error = "No hay productos en el carrito.";
    } else {
        $fecha = date('Y-m-d');
        $stmt = $conn->prepare("CALL RegistrarPedido(?, ?, ?)");
        $stmt->bind_param("iis", $cliente_id, $idEmpleado, $fecha);
        $stmt->execute();
        $stmt->close();
        limpiarResultadosPendientes($conn);

        $stmt = $conn->prepare("CALL VaciarCarritoEmpleado(?)");
        $stmt->bind_param("i", $idEmpleado);
        $stmt->execute();
        $stmt->close();
        limpiarResultadosPendientes($conn);

        $productos_registrados = [];
        $mensaje_exito = "Pedido registrado correctamente.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ajentes de Ventas</title>
    <link rel="stylesheet" href="HacerPedido2.css">
    <link rel="icon" href="Imagenes2/DESTORNILLADOR.jpg" />
</head>
<body>
    <div class="overlay"></div>
    <header class="titulo">
        <div class="boton-atras-contenedor">
            <a href="Login.php"><img src="Imagenes2/regresar.jpg" alt="Salir" class="boton-atras" /></a>
        </div>
        <h1>HERRERIA "METALURGIA 360"</h1>
    </header>
    <main class="contenido">
        <section class="container">
            <?php if ($mensaje_error): ?>
                <div style="color:red; font-weight:bold;"><?= htmlspecialchars($mensaje_error) ?></div>
            <?php endif; ?>
            <?php if ($mensaje_exito): ?>
                <div style="color:green; font-weight:bold;"><?= htmlspecialchars($mensaje_exito) ?></div>
            <?php endif; ?>

            <form method="POST" class="barcode-form">
                <input type="text" name="termino" placeholder="Nombre o código" value="<?= htmlspecialchars($termino_busqueda) ?>" />
                <select name="cliente_id" required>
                    <option value="">Seleccione un cliente</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?= $cliente['id'] ?>" <?= ($cliente_id == $cliente['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cliente['Nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label><input type="checkbox" name="es_credito" <?= $es_credito ? 'checked' : '' ?>> Crédito</label>
                <button type="submit" name="buscar">Buscar</button>
                <button type="submit" name="procesar">Hacer Pedido</button>
            </form>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Precio</th>
                            <th>Total</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total = 0;
                        if ($productos_registrados) {
                            foreach ($productos_registrados as $p) {
                                $total += $p['precioProducto'] * $p['cantidad'];
                                echo "<tr>
                                    <td>" . htmlspecialchars($p['nombre']) . "</td>
                                    <td>" . intval($p['cantidad']) . "</td>
                                    <td>$" . number_format($p['precioProducto'], 2) . "</td>
                                    <td>$" . number_format($p['precioVenta'], 2) . "</td>
                                    <td>
                                        <form method='POST' style='display:inline'>
                                            <input type='hidden' name='producto_id' value='" . intval($p['idProducto']) . "'>
                                            <button type='submit' name='sumar'>+</button>
                                            <button type='submit' name='restar'>-</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5'>No hay productos en el carrito.</td></tr>";
                        }
                        ?>
                        <tr>
                            <td colspan="3" style="text-align:right"><strong>Total:</strong></td>
                            <td colspan="2"><strong>$<?= number_format($total, 2) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>