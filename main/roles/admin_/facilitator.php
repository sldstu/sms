<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../../database/database.class.php';
$conn = (new Database())->connect();

// Fetch existing facilitators from users table
$query = $conn->prepare(
    "SELECT u.*
     FROM users u
     WHERE u.role = 'facilitator'"
);
$query->execute();
$facilitators = $query->fetchAll(PDO::FETCH_ASSOC);

// Fetch potential facilitators (users who could become facilitators)
$user_query = $conn->prepare("SELECT * FROM users WHERE role IN ('student', 'moderator')");
$user_query->execute();
$potential_facilitators = $user_query->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (isset($input['action']) && $input['action'] === 'remove_facilitator' && isset($input['user_id'])) {
        $user_id = $input['user_id'];

        try {
            // Update sports table to remove facilitator association
            $updateSports = $conn->prepare("UPDATE sports SET facilitator_id = NULL WHERE facilitator_id = :user_id");
            $updateSports->bindParam(':user_id', $user_id);
            $updateSports->execute();

            // Update user role back to student
            $query = $conn->prepare("UPDATE users SET role = 'student' WHERE user_id = :user_id");
            $query->bindParam(':user_id', $user_id);
            $query->execute();

            echo json_encode(['success' => true]);
            exit();
        } catch (Exception $e) {
            error_log('Error removing facilitator: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error while removing facilitator.']);
            exit();
        }
    } elseif (isset($input['action']) && $input['action'] === 'assign_facilitator' && isset($input['user_id'])) {
        $user_id = $input['user_id'];

        try {
            // Update user role to facilitator
            $query = $conn->prepare("UPDATE users SET role = 'facilitator' WHERE user_id = :user_id");
            $query->bindParam(':user_id', $user_id);

            if ($query->execute()) {
                // Fetch updated user info
                $user_query = $conn->prepare("SELECT * FROM users WHERE user_id = :user_id");
                $user_query->bindParam(':user_id', $user_id);
                $user_query->execute();
                $user = $user_query->fetch(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'facilitator' => $user
                ]);
                exit();
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error assigning facilitator: ' . $e->getMessage()]);
            exit();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Facilitators</title>
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"> -->
    <link rel="stylesheet" href="/SMS/css/style.css">
</head>

<body>
    <div id="facilitators_section" class="container mt-4">
        <h2 class="mb-4">Facilitator Management</h2>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <input type="text" id="search_bar" class="form-control w-auto" placeholder="Search facilitators..." onkeyup="searchFacilitator()">
            <button class="btn btn-primary px-4 shadow-sm" onclick="sortTable()">Sort Alphabetically</button>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assignFacilitatorModal">
                Assign a Facilitator
            </button>
        </div>

        <!-- Facilitators Table -->
        <div class="table-responsive">
            <table id="facilitators_table" class="table table-hover align-middle table-bordered rounded-3 overflow-hidden shadow">
                <thead class="table-primary">
                    <tr class="text-center">
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Assigned Sports</th>
                        <th>Last Online</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facilitators as $facilitator): ?>
                        <tr id="facilitator-row-<?= $facilitator['user_id'] ?>">
                            <td><?= htmlspecialchars($facilitator['username']) ?></td>
                            <td><?= htmlspecialchars($facilitator['first_name'] . ' ' . $facilitator['middle_name'] . ' ' . $facilitator['last_name']) ?></td>
                            <td><?= htmlspecialchars($facilitator['email']) ?></td>
                            <td><?= htmlspecialchars($facilitator['assigned_sports'] ?? 'None') ?></td>
                            <td><?= htmlspecialchars($facilitator['datetime_last_online']) ?></td>
                            <td class="text-center">
                                <button class="btn btn-danger btn-sm remove-facilitator-btn px-3"
                                    data-user-id="<?= $facilitator['user_id'] ?>"
                                    data-username="<?= htmlspecialchars($facilitator['username']) ?>">
                                    Remove as Facilitator
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Assign Facilitator Modal -->
        <div class="modal fade" id="assignFacilitatorModal" tabindex="-1" aria-labelledby="assignFacilitatorModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="assignFacilitatorModalLabel">Assign a Facilitator</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="assignFacilitatorForm" novalidate>
                            <div class="mb-3">
                                <label for="user_id" class="form-label">Select User</label>
                                <select class="form-select" id="user_id" name="user_id" required>
                                    <option value="" selected disabled>Select a user...</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= htmlspecialchars($user['user_id']) ?>">
                                            <?= htmlspecialchars($user['first_name']) . ' ' . htmlspecialchars($user['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Please select a user.
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="assignFacilitatorBtn">Assign Facilitator</button>
                    </div>
                </div>
            </div>
        </div>


        <!-- Confirmation Modal -->
        <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmationModalLabel">Confirm Removal</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p id="confirmationMessage"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmRemoveBtn">Remove</button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            function searchFacilitator() {
                var input, filter, table, tr, td, i, txtValue;
                input = document.getElementById("search_bar");
                filter = input.value.toUpperCase();
                table = document.getElementById("facilitators_table");
                tr = table.getElementsByTagName("tr");

                for (i = 1; i < tr.length; i++) {
                    td = tr[i].getElementsByTagName("td")[2];
                    if (td) {
                        txtValue = td.textContent || td.innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            tr[i].style.display = "";
                        } else {
                            tr[i].style.display = "none";
                        }
                    }
                }
            }

            function sortTable() {
                var table, rows, switching, i, x, y, shouldSwitch;
                table = document.getElementById("facilitators_table");
                switching = true;
                while (switching) {
                    switching = false;
                    rows = table.rows;
                    for (i = 1; i < (rows.length - 1); i++) {
                        shouldSwitch = false;
                        x = rows[i].getElementsByTagName("td")[2];
                        y = rows[i + 1].getElementsByTagName("td")[2];
                        if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                    if (shouldSwitch) {
                        rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                        switching = true;
                    }
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                // Event listener for removing a facilitator
                document.querySelectorAll('.remove-facilitator-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        const facilitatorId = this.dataset.facilitatorId;
                        const email = this.dataset.email;

                        document.getElementById('confirmationMessage').textContent = `Are you sure you want to remove the facilitator with email ${email}?`;

                        const modal = new bootstrap.Modal(document.getElementById('confirmationModal'));
                        modal.show();

                        const confirmRemoveBtn = document.getElementById('confirmRemoveBtn');
                        confirmRemoveBtn.replaceWith(confirmRemoveBtn.cloneNode(true));
                        const newConfirmRemoveBtn = document.getElementById('confirmRemoveBtn');

                        newConfirmRemoveBtn.addEventListener('click', function() {
                            fetch("../main/roles/admin_/facilitator.php", {
                                    method: "POST",
                                    headers: {
                                        "Content-Type": "application/json"
                                    },
                                    body: JSON.stringify({
                                        action: "remove_facilitator",
                                        facilitator_id: facilitatorId
                                    })
                                })
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success) {
                                        const facilitatorRow = document.getElementById(`facilitator-row-${facilitatorId}`);
                                        if (facilitatorRow) {
                                            facilitatorRow.remove();
                                        }
                                        const modalInstance = bootstrap.Modal.getInstance(document.getElementById("confirmationModal"));
                                        modalInstance.hide();
                                    } else {
                                        alert(data.message || "Failed to remove facilitator.");
                                    }
                                })
                                .catch(error => {
                                    console.error("Error during remove operation:", error);
                                    alert("An error occurred while removing the facilitator.");
                                });
                        });
                    });
                });

                // Assign Facilitator
                document.getElementById("assignFacilitatorBtn").addEventListener("click", () => {
                    const form = document.getElementById("assignFacilitatorForm");
                    const formData = new FormData(form);

                    const userId = formData.get("user_id");
                    if (!userId) {
                        form.querySelector("#user_id").classList.add("is-invalid");
                        return;
                    }

                    const data = {
                        action: "assign_facilitator",
                        user_id: userId
                    };

                    fetch("../main/roles/admin_/facilitator.php", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json"
                            },
                            body: JSON.stringify(data),
                        })
                        .then((response) => response.json())
                        .then((result) => {
                            if (result.success) {
                                location.reload();
                            } else {
                                alert(result.message || "Failed to assign facilitator.");
                            }
                        })
                        .catch((error) => console.error("Error:", error));
                });

                document.querySelectorAll("#assignFacilitatorModal .modal-body select[required]").forEach(select => {
                    select.addEventListener("change", function() {
                        if (this.value) {
                            this.classList.remove("is-invalid");
                            const invalidFeedback = this.nextElementSibling;
                            if (invalidFeedback) {
                                invalidFeedback.style.display = "none";
                            }
                        }
                    });
                });
            });
        </script>