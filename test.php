  <?php
  // Xóa user cũ, tạo user mới, chuyển posts sang user mới
  $oldUser = $em->query(User::class)->find(1);
  $newUser = new User();
  $newUser->name = 'John';

  // Chuyển posts sang user mới
  $post = $em->query(Post::class)->find(10);
  $post->userId = $newUser->id;  // FK: posts.user_id -> users.id

  $em->persist($newUser);   // scheduled INSERT
  $em->markDirty($post);    // scheduled UPDATE
  $em->remove($oldUser);    // scheduled DELETE
  $em->commit();

  //Nếu DELETE trước INSERT:
  1. DELETE $oldUser -> OK
  2. INSERT $newUser -> OK, nhưng $newUser->id chưa có lúc UPDATE
  3. UPDATE $post set user_id = NULL -> Lỗi FK hoặc data sai

  //Với thứ tự INSERT -> UPDATE -> DELETE:
  1. INSERT $newUser -> $newUser->id = 2 (auto-generated)
  2. UPDATE $post set user_id = 2 -> OK (user 2 đã tồn tại)
  3. DELETE $oldUser -> OK (không còn post nào reference)