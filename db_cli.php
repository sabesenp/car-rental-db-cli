<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 'On');

/* ---------- simple .env loader (KEY=VALUE per line) ---------- */
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
  $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    putenv("$k=$v");
  }
}

/* ---------- DB connect ---------- */
function connectToDatabase() {
  $user = getenv('DB_USER') ?: '';
  $pass = getenv('DB_PASS') ?: '';
  // keep DSN format same, just from env to avoid hardcoding
  $dsn  = getenv('DB_DSN') ?: <<<EOD
(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=oracle.scs.ryerson.ca)(PORT=1521))(CONNECT_DATA=(SID=orcl)))
EOD;

  if ($user === '' || $pass === '') {
    fwrite(STDERR, "Missing DB_USER/DB_PASS in .env\n");
    exit(1);
  }

  $conn = @oci_connect($user, $pass, $dsn);
  if (!$conn) {
    $m = oci_error();
    echo "Connection failed: " . $m['message'] . PHP_EOL;
    exit(1);
  }
  return $conn;
}

/* ---------- run a statement with basic error handling ---------- */
function executeQuery($conn, $query) {
  $stid = @oci_parse($conn, $query);
  if (!$stid) {
    $e = oci_error($conn);
    echo "Parse error: " . ($e['message'] ?? 'unknown') . PHP_EOL;
    return;
  }
  if (!@oci_execute($stid)) {
    $e = oci_error($stid);
    echo "Error executing query: " . ($e['message'] ?? 'unknown') . PHP_EOL;
  } else {
    echo "Query executed successfully." . PHP_EOL;
  }
  oci_free_statement($stid);
}

/* ---------- DROP ---------- */
function dropTables($conn) {
  $tables = [
    "BILLING_INFORMATION",
    "RENTAL_RECORDS",
    "MAINTENANCE",
    "CAR_INFORMATION",
    "CUSTOMER_INFORMATION",
    "BRANCH_INFORMATION"
  ];
  foreach ($tables as $table) {
    $query = "
      BEGIN
        EXECUTE IMMEDIATE 'DROP TABLE $table CASCADE CONSTRAINTS';
      EXCEPTION WHEN OTHERS THEN NULL;
      END;";
    executeQuery($conn, $query);
  }
  echo "Tables dropped successfully (or they did not exist)." . PHP_EOL;
}

/* ---------- CREATE ---------- */
function createTables($conn) {
  $queries = [
    // BRANCH_INFORMATION
    "CREATE TABLE BRANCH_INFORMATION (
      Branch_ID NUMBER PRIMARY KEY,
      Name VARCHAR2(100),
      Address VARCHAR2(255),
      City VARCHAR2(100),
      Phone_Number VARCHAR2(15),
      Email VARCHAR2(100)
    )",

    // CAR_INFORMATION
    "CREATE TABLE CAR_INFORMATION (
      Car_ID NUMBER PRIMARY KEY,
      Branch_ID NUMBER,
      Make VARCHAR2(50),
      Model VARCHAR2(50),
      Year_Of_Manufacture NUMBER,
      Colour VARCHAR2(30),
      Number_Of_Seats NUMBER,
      Per_Day_Rental_Price NUMBER(10, 2),
      FOREIGN KEY (Branch_ID) REFERENCES BRANCH_INFORMATION (Branch_ID)
    )",

    // CUSTOMER_INFORMATION
    "CREATE TABLE CUSTOMER_INFORMATION (
      Customer_ID NUMBER PRIMARY KEY,
      First_Name VARCHAR2(100),
      Last_Name VARCHAR2(100),
      Date_Of_Birth DATE,
      Email VARCHAR2(100),
      Phone_Number VARCHAR2(15)
    )",

    // RENTAL_RECORDS
    "CREATE TABLE RENTAL_RECORDS (
      Rental_ID NUMBER PRIMARY KEY,
      Customer_ID NUMBER,
      Car_ID NUMBER,
      Pickup_Branch_ID NUMBER,
      Dropoff_Branch_ID NUMBER,
      Pickup_Date DATE,
      Dropoff_Date DATE,
      FOREIGN KEY (Customer_ID) REFERENCES CUSTOMER_INFORMATION (Customer_ID),
      FOREIGN KEY (Car_ID) REFERENCES CAR_INFORMATION (Car_ID),
      FOREIGN KEY (Pickup_Branch_ID) REFERENCES BRANCH_INFORMATION (Branch_ID),
      FOREIGN KEY (Dropoff_Branch_ID) REFERENCES BRANCH_INFORMATION (Branch_ID)
    )",

    // BILLING_INFORMATION
    "CREATE TABLE BILLING_INFORMATION (
      Payment_ID NUMBER PRIMARY KEY,
      Customer_ID NUMBER,
      Rental_ID NUMBER,
      Amount NUMBER(10, 2),
      Payment_Date DATE,
      Card_Number VARCHAR2(20),
      FOREIGN KEY (Customer_ID) REFERENCES CUSTOMER_INFORMATION (Customer_ID),
      FOREIGN KEY (Rental_ID) REFERENCES RENTAL_RECORDS (Rental_ID)
    )",

    // MAINTENANCE
    "CREATE TABLE MAINTENANCE (
      Maintenance_ID NUMBER PRIMARY KEY,
      Car_ID NUMBER,
      Maintenance_Date DATE,
      Description VARCHAR2(255),
      Estimate_Cost NUMBER(10, 2),
      FOREIGN KEY (Car_ID) REFERENCES CAR_INFORMATION (Car_ID)
    )"
  ];

  foreach ($queries as $query) {
    executeQuery($conn, $query);
  }
  echo "Tables created successfully." . PHP_EOL;
}

/* ---------- SEED ---------- */
function populateTables($conn) {
  $queries = [
    // --- Branches ---
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (1, 'Downtown Branch', '123 Main Street', 'Toronto', '416-555-1234', 'downtown@carrentals.com')",
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (2, 'Airport Branch', '456 Airport Road', 'Toronto', '416-555-5678', 'airport@carrentals.com')",
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (3, 'Midtown Branch', '789 Midtown Avenue', 'New York', '212-555-8765', 'midtown@carrentals.com')",
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (4, 'Eastside Branch', '321 Eastside Blvd', 'Toronto', '416-555-2222', 'eastside@carrentals.com')",
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (5, 'Uptown Branch', '123 Uptown Drive', 'New York', '212-555-8765', 'uptown@carrentals.com')",
    "INSERT INTO BRANCH_INFORMATION (Branch_ID, Name, Address, City, Phone_Number, Email)
     VALUES (6, 'Westside Branch', '987 Westside Road', 'Vancouver', '604-555-4321', 'westside@carrentals.com')",

    // --- Cars ---
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (1, 1, 'Toyota', 'Camry', 2020, 'Red', 5, 50.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (2, 1, 'Honda', 'Civic', 2019, 'Blue', 5, 45.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (3, 2, 'Ford', 'Mustang', 2021, 'Black', 4, 80.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (4, 3, 'BMW', 'X5', 2022, 'White', 5, 100.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (5, 1, 'Chevrolet', 'Malibu', 2021, 'Silver', 5, 55.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (6, 2, 'Hyundai', 'Elantra', 2022, 'Gray', 5, 48.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (7, 3, 'Mercedes', 'C-Class', 2023, 'Black', 5, 120.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (8, 4, 'Nissan', 'Altima', 2019, 'Blue', 5, 60.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (9, 5, 'Mazda', 'CX-5', 2020, 'Red', 5, 75.00)",
    "INSERT INTO CAR_INFORMATION (Car_ID, Branch_ID, Make, Model, Year_Of_Manufacture, Colour, Number_Of_Seats, Per_Day_Rental_Price)
     VALUES (10, 6, 'Audi', 'RS-5', 2023, 'Red', 3, 95.00)",

    // --- Customers ---
    "INSERT INTO CUSTOMER_INFORMATION (Customer_ID, First_Name, Last_Name, Date_Of_Birth, Email, Phone_Number)
     VALUES (1, 'John', 'Doe', TO_DATE('1990-06-15', 'YYYY-MM-DD'), 'john.doe@example.com', '416-555-6789')",
    "INSERT INTO CUSTOMER_INFORMATION (Customer_ID, First_Name, Last_Name, Date_Of_Birth, Email, Phone_Number)
     VALUES (2, 'Jane', 'Smith', TO_DATE('1985-03-22', 'YYYY-MM-DD'), 'jane.smith@example.com', '416-555-1234')",
    "INSERT INTO CUSTOMER_INFORMATION (Customer_ID, First_Name, Last_Name, Date_Of_Birth, Email, Phone_Number)
     VALUES (3, 'Emily', 'Johnson', TO_DATE('1995-11-10', 'YYYY-MM-DD'), 'emily.johnson@example.com', '905-555-5678')",
    "INSERT INTO CUSTOMER_INFORMATION (Customer_ID, First_Name, Last_Name, Date_Of_Birth, Email, Phone_Number)
     VALUES (4, 'Michael', 'Brown', TO_DATE('1988-08-08', 'YYYY-MM-DD'), 'michael.brown@example.com', '905-555-9012')",
    "INSERT INTO CUSTOMER_INFORMATION (Customer_ID, First_Name, Last_Name, Date_Of_Birth, Email, Phone_Number)
     VALUES (5, 'Sophia', 'Davis', TO_DATE('1993-01-25', 'YYYY-MM-DD'), 'sophia.davis@example.com', '416-555-2345')",

    // --- Rentals ---
    "INSERT INTO RENTAL_RECORDS (Rental_ID, Customer_ID, Car_ID, Pickup_Branch_ID, Dropoff_Branch_ID, Pickup_Date, Dropoff_Date)
     VALUES (1, 1, 1, 1, 1, TO_DATE('2024-11-20', 'YYYY-MM-DD'), TO_DATE('2024-11-23', 'YYYY-MM-DD'))",
    "INSERT INTO RENTAL_RECORDS (Rental_ID, Customer_ID, Car_ID, Pickup_Branch_ID, Dropoff_Branch_ID, Pickup_Date, Dropoff_Date)
     VALUES (2, 2, 2, 2, 3, TO_DATE('2024-11-15', 'YYYY-MM-DD'), TO_DATE('2024-11-18', 'YYYY-MM-DD'))",
    "INSERT INTO RENTAL_RECORDS (Rental_ID, Customer_ID, Car_ID, Pickup_Branch_ID, Dropoff_Branch_ID, Pickup_Date, Dropoff_Date)
     VALUES (3, 3, 3, 3, 4, TO_DATE('2024-11-10', 'YYYY-MM-DD'), TO_DATE('2024-11-12', 'YYYY-MM-DD'))",
    "INSERT INTO RENTAL_RECORDS (Rental_ID, Customer_ID, Car_ID, Pickup_Branch_ID, Dropoff_Branch_ID, Pickup_Date, Dropoff_Date)
     VALUES (4, 4, 4, 4, 5, TO_DATE('2024-11-05', 'YYYY-MM-DD'), TO_DATE('2024-11-08', 'YYYY-MM-DD'))",
    "INSERT INTO RENTAL_RECORDS (Rental_ID, Customer_ID, Car_ID, Pickup_Branch_ID, Dropoff_Branch_ID, Pickup_Date, Dropoff_Date)
     VALUES (5, 5, 5, 5, 1, TO_DATE('2024-11-01', 'YYYY-MM-DD'), TO_DATE('2024-11-04', 'YYYY-MM-DD'))",

    // --- Billing (demo numbers) ---
    "INSERT INTO BILLING_INFORMATION (Payment_ID, Customer_ID, Rental_ID, Amount, Payment_Date, Card_Number)
     VALUES (1, 1, 1, 150.00, TO_DATE('2024-11-20', 'YYYY-MM-DD'), '4111111111111111')",
    "INSERT INTO BILLING_INFORMATION (Payment_ID, Customer_ID, Rental_ID, Amount, Payment_Date, Card_Number)
     VALUES (2, 2, 2, 165.00, TO_DATE('2024-11-15', 'YYYY-MM-DD'), '4111111111111111')",
    "INSERT INTO BILLING_INFORMATION (Payment_ID, Customer_ID, Rental_ID, Amount, Payment_Date, Card_Number)
     VALUES (3, 3, 3, 130.00, TO_DATE('2024-11-10', 'YYYY-MM-DD'), '4111111111111111')",
    "INSERT INTO BILLING_INFORMATION (Payment_ID, Customer_ID, Rental_ID, Amount, Payment_Date, Card_Number)
     VALUES (4, 4, 4, 135.00, TO_DATE('2024-11-05', 'YYYY-MM-DD'), '4111111111111111')",
    "INSERT INTO BILLING_INFORMATION (Payment_ID, Customer_ID, Rental_ID, Amount, Payment_Date, Card_Number)
     VALUES (5, 5, 5, 180.00, TO_DATE('2024-11-01', 'YYYY-MM-DD'), '4111111111111111')",

    // --- Maintenance ---
    "INSERT INTO MAINTENANCE (Maintenance_ID, Car_ID, Maintenance_Date, Description, Estimate_Cost)
     VALUES (1, 1, TO_DATE('2024-11-10', 'YYYY-MM-DD'), 'Oil Change', 30.00)",
    "INSERT INTO MAINTENANCE (Maintenance_ID, Car_ID, Maintenance_Date, Description, Estimate_Cost)
     VALUES (2, 2, TO_DATE('2024-11-15', 'YYYY-MM-DD'), 'Tire Replacement', 120.00)",
    "INSERT INTO MAINTENANCE (Maintenance_ID, Car_ID, Maintenance_Date, Description, Estimate_Cost)
     VALUES (3, 3, TO_DATE('2024-11-20', 'YYYY-MM-DD'), 'Battery Check', 50.00)",
    "INSERT INTO MAINTENANCE (Maintenance_ID, Car_ID, Maintenance_Date, Description, Estimate_Cost)
     VALUES (4, 4, TO_DATE('2024-11-25', 'YYYY-MM-DD'), 'Brake Pads', 200.00)",
    "INSERT INTO MAINTENANCE (Maintenance_ID, Car_ID, Maintenance_Date, Description, Estimate_Cost)
     VALUES (5, 5, TO_DATE('2024-11-30', 'YYYY-MM-DD'), 'Air Filter Replacement', 40.00)",
  ];

  foreach ($queries as $query) {
    executeQuery($conn, $query);
  }
  echo "Tables populated successfully." . PHP_EOL;
}

/* ---------- SELECT helper ---------- */
function queryData($conn, $query) {
  $stid = @oci_parse($conn, $query);
  if (!$stid) {
    $e = oci_error($conn);
    echo "Parse error: " . ($e['message'] ?? 'unknown') . PHP_EOL;
    return;
  }
  if (!@oci_execute($stid)) {
    $e = oci_error($stid);
    echo "Error executing query: " . ($e['message'] ?? 'unknown') . PHP_EOL;
    oci_free_statement($stid);
    return;
  }
  while ($row = oci_fetch_assoc($stid)) {
    print_r($row);
  }
  oci_free_statement($stid);
}

/* ---------- MENU (unchanged UX) ---------- */
while (true) {
  echo PHP_EOL . "--- Database Operations Menu ---" . PHP_EOL;
  echo "1. Drop Tables" . PHP_EOL;
  echo "2. Create Tables" . PHP_EOL;
  echo "3. Populate Tables" . PHP_EOL;
  echo "4. Query Tables" . PHP_EOL;
  echo "5. Exit" . PHP_EOL;
  echo "Enter your choice: ";

  $choice = trim(fgets(STDIN));

  switch ($choice) {
    case '1':
      $conn = connectToDatabase();
      dropTables($conn);
      oci_close($conn);
      break;

    case '2':
      $conn = connectToDatabase();
      createTables($conn);
      oci_close($conn);
      break;

    case '3':
      $conn = connectToDatabase();
      populateTables($conn);
      oci_close($conn);
      break;

    case '4':
      echo "Enter your SQL query: ";
      $query = trim(fgets(STDIN));
      $conn = connectToDatabase();
      queryData($conn, $query);
      oci_close($conn);
      break;

    case '5':
      echo "Exiting..." . PHP_EOL;
      exit;

    default:
      echo "Invalid choice. Try again." . PHP_EOL;
  }
}
