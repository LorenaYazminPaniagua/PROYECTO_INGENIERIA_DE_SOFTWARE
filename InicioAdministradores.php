<?php
session_start();
$conn = new mysqli("localhost", "root", "", "HerreriaUG");
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener idEmpleado desde sesión o default
$idEmpleado = $_SESSION['id'] ?? 1;
$conn->query("SET @id_empleado_sesion := $idEmpleado");
$productos_registrados = [];


// Obtener lista de clientes activos para el dropdown
$clientes = [];
$sql = "SELECT c.idCliente AS id, p.Nombre
        FROM Clientes c
        INNER JOIN Personas p ON c.idPersona = p.idPersona
        WHERE p.Estatus = 1";
$result = $conn->query($sql);
if ($result) {
    $clientes = $result->fetch_all(MYSQLI_ASSOC);
} else {
    die("Error al obtener clientes: " . $conn->error);
}

// Cliente seleccionado (POST o vacío)
$cliente_id = $_POST['cliente_id'] ?? '';
$tipo_cliente = 'normal'; // Valor por defecto si no se encuentra tipo

// Obtener tipo de cliente (categoría) desde tabla Descuentos usando idCliente
if (!empty($cliente_id)) {
    $stmtTipoCliente = $conn->prepare("
        SELECT TRIM(d.Categoria) AS Categoria 
        FROM Clientes c 
        JOIN Descuentos d ON c.idDescuento = d.idDescuento 
        WHERE c.idCliente = ?
    ");
    if ($stmtTipoCliente === false) {
        die("Error en prepare tipo cliente: " . $conn->error);
    }
    $stmtTipoCliente->bind_param("i", $cliente_id);
    $stmtTipoCliente->execute();
    $resTipo = $stmtTipoCliente->get_result();
    if ($resTipo && $rowTipo = $resTipo->fetch_assoc()) {
        $tipo_cliente = strtolower(trim($rowTipo['Categoria']));
    }
    $stmtTipoCliente->close();
    $conn->next_result();
}

// Si se busca un producto (botón buscar presionado)
if (isset($_POST['buscar'])) {
    $termino = trim($_POST['termino']);
    $producto = null;

    // Buscar producto por nombre (procedimiento almacenado)
    $stmt = $conn->prepare("CALL BuscarProductoPorNombre(?)");
    if ($stmt === false) {
        die("Error en prepare BuscarProductoPorNombre: " . $conn->error);
    }
    $stmt->bind_param("s", $termino);
    $stmt->execute();
    $resultadoNombre = $stmt->get_result();
    $producto = $resultadoNombre->fetch_assoc();
    $stmt->close();
    $conn->next_result();

    // Si no encontrado por nombre, buscar por código de barras
    if (!$producto) {
        $stmt = $conn->prepare("CALL BuscarProductoPorCodigoBarras(?)");
        if ($stmt === false) {
            die("Error en prepare BuscarProductoPorCodigoBarras: " . $conn->error);
        }
        $stmt->bind_param("s", $termino);
        $stmt->execute();
        $resultadoCodigo = $stmt->get_result();
        $producto = $resultadoCodigo->fetch_assoc();
        $stmt->close();
        $conn->next_result();
    }

    if ($producto) {
        $cantidad = 1;
        $idProducto = $producto['idProducto'];

        // Calcular precio con descuento con SP
        $stmtPrecio = $conn->prepare("CALL CalcularPrecioConDescuento(?, ?, @precioFinal)");
        if ($stmtPrecio === false) {
            die("Error en prepare CalcularPrecioConDescuento: " . $conn->error);
        }
        $stmtPrecio->bind_param("is", $idProducto, $tipo_cliente);
        $stmtPrecio->execute();
        $stmtPrecio->close();
        $conn->next_result();

        // Obtener valor de precio con descuento OUT
        $res = $conn->query("SELECT @precioFinal AS precioConDescuento");
        $row = $res->fetch_assoc();
        $precioConDescuento = floatval($row['precioConDescuento'] ?? 0.0);

        // Agregar producto al carrito con precio con descuento
        $stmt = $conn->prepare("CALL AgregarAlCarrito(?, ?, ?, ?)");
        if ($stmt === false) {
            die("Error en prepare AgregarAlCarrito: " . $conn->error);
        }
        $stmt->bind_param("iiid", $idEmpleado, $idProducto, $cantidad, $precioConDescuento);
        if (!$stmt->execute()) {
            die("Error en execute AgregarAlCarrito: " . $stmt->error);
        }
        $stmt->close();

        // Limpiar resultados pendientes para evitar errores
        while ($conn->more_results() && $conn->next_result()) {
            $res = $conn->store_result();
            if ($res instanceof mysqli_result) {
                $res->free();
            }
        }
    } else {
        echo "<p>Producto no encontrado.</p>";
    }
}

// Sumar cantidad
if (isset($_POST['sumar'])) {
    $idProducto = $_POST['producto_id'];
    $stmt = $conn->prepare("CALL SumarCantidadProductoCarrito(?, ?)");
    if ($stmt === false) {
        die("Error en prepare SumarCantidadProductoCarrito: " . $conn->error);
    }
    $stmt->bind_param("ii", $idEmpleado, $idProducto);
    $stmt->execute();
    $stmt->close();
    $conn->next_result();
}

// Restar cantidad
if (isset($_POST['restar'])) {
    $idProducto = $_POST['producto_id'];
    $stmt = $conn->prepare("CALL RestarCantidadProductoCarrito(?, ?)");
    if ($stmt === false) {
        die("Error en prepare RestarCantidadProductoCarrito: " . $conn->error);
    }
    $stmt->bind_param("ii", $idEmpleado, $idProducto);
    $stmt->execute();
    $stmt->close();
    $conn->next_result();
}

// Procesar venta
if (isset($_POST['procesar'])) {
    $cliente_id = $_POST['cliente_id'] ?? '';
    if (empty($cliente_id)) {
        die("Debe seleccionar un cliente para procesar la venta.");
    }
    $tipo_pago = isset($_POST['es_credito']) ? 'Credito' : 'Contado';
    $pago = 0.0;

    $stmt = $conn->prepare("CALL ProcesarUnaVenta(?, ?, ?, ?)");
    if ($stmt === false) {
        die("Error en prepare ProcesarUnaVenta: " . $conn->error);
    }
    $stmt->bind_param("iisd", $idEmpleado, $cliente_id, $tipo_pago, $pago);
    if (!$stmt->execute()) {
        die("Error en execute ProcesarUnaVenta: " . $stmt->error);
    }
    $stmt->close();
    $conn->next_result();
    $productos_registrados = []; // Evita warning por variable indefinida

}

// Obtener productos del carrito
$stmt = $conn->prepare("CALL sp_ObtenerCarritoPorEmpleado(?)");
if ($stmt === false) {
    die("Error en prepare sp_ObtenerCarritoPorEmpleado: " . $conn->error);
}
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
$conn->next_result();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>VentasAdministradores</title>
    <link rel="stylesheet" href="InicioAdministradores.css" />
    <link rel="icon" type="image/jpg" href="Imagenes2/DESTORNILLADOR.jpg" />
</head>
<body>
    <div class="overlay"></div>

    <header class="titulo">
        <div class="boton-atras-contenedor">
            <a href="ADMINISTRADORES.html">
                            <img src="Imagenes2/regresar.jpg" alt="Botón Atrás" class="boton-atras">
                        </a>
        </div>


        <h1>HERRERIA "METALURGIA 360"</h1>
    </header>

    <main class="contenido">
        <section class="container">
            <form method="POST" class="barcode-form">
                <input type="text" name="termino" placeholder="Nombre o código de barras" value="<?= htmlspecialchars($_POST['termino'] ?? '') ?>" />

                <select name="cliente_id" required>
                    <option value="">Seleccione un cliente</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?= htmlspecialchars($cliente['id']) ?>" <?= ($cliente_id == $cliente['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cliente['Nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="buscar">Buscar producto</button>

                <label>
                    <input type="checkbox" name="es_credito" <?= isset($_POST['es_credito']) ? 'checked' : '' ?> />
                    Crédito
                </label>

                <button type="submit" name="procesar">Procesar Venta</button>
            </form>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr class="headtable">
                            <th>Producto</th>
                            <th>Cantidad</th>
                            <th>Precio Unitario</th>
                            <th>Total</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total = 0;
                        if ($productos_registrados) {
                            foreach ($productos_registrados as $row) {
                                $total += $row['precioProducto'] * $row['cantidad'];
                                echo "<tr>
                                    <td>" . htmlspecialchars($row['nombre']) . "</td>
                                    <td>" . intval($row['cantidad']) . "</td>
                                    <td>$" . number_format($row['precioProducto'], 2) . "</td>
                                    <td>$" . number_format($row['precioVenta'], 2) . "</td>
                                    <td>
                                        <form method='POST' style='display:inline'>
                                            <input type='hidden' name='producto_id' value='" . intval($row['idProducto']) . "' />
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
                            <td colspan="3" style="text-align:right; font-weight:bold;">Total:</td>
                            <td colspan="2" style="font-weight:bold;">$<?= number_format($total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>